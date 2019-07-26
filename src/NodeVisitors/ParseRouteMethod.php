<?php

namespace Waavi\LaravelOpenApiGenerator\NodeVisitors;

use PhpParser\Comment;
use PhpParser\Node;

class ParseRouteMethod extends BaseNodeVisitor
{
    protected $assignments = [];

    protected $method;

    protected $comment;

    protected $formRequest;

    protected $parameters = [];

    protected $responses = [];

    protected $callback;

    public function __construct($callback)
    {
        $this->callback = $callback;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->method = (string) $node->name;

            if ($docComment = $node->getDocComment()) {
                $this->comment = $this->parseComment($docComment);
            }

            foreach ($node->params as $param) {
                $this->parseRouteParameter($param);
            }
        }

        if ($node instanceof Node\Expr\Assign) {
            return $this->parseAssignment($node);
        }

        if ($node instanceof Node\Stmt\Return_) {
            return $this->parseReturn($node);
        }

        return null;
    }

    public function afterTraverse(array $nodes)
    {
        ($this->callback)([
            'method' => $this->method,
            'summary' => explode("\n", $this->comment ?: '')[0],
            'description' => $this->comment ?: '',
            'formRequest' => $this->formRequest,
            'parameters' => $this->parameters,
            'responses' => $this->responses,
        ]);
    }

    protected function parseComment(Comment $comment)
    {
        // Clean up comment punctuation
        $commentText = collect(explode("\n", $comment->getText()))
            ->map(function($line) {
                return trim($line, "\t\n /*");
            })
            // Remove lines with @param, @return, etc. Only keep comments.
            ->filter(function($line) {
                return !strlen($line) || $line[0] !== '@';
            })
            ->implode("\n");

        // Remove empty starting and ending lines.
        return trim($commentText);
    }

    protected function parseRouteParameter(Node\Param $node)
    {
        $paramType = $node->type ? $node->type->toString() : null;
        $paramName = (string) $node->var->name;

        if (!$node->type || $node->type instanceof Node\Identifier) {
            $this->parameters[$paramName] = [
                'type' => 'native',
                'native' => $paramType,
                'name' => $paramName,
            ];
            return null;
        }

        if ($this->isFormRequest($paramType)) {
            $this->formRequest = $node->type->toString();
            return null;
        }

        if ($this->isModel($paramType)) {
            $this->parameters[$paramName] = [
                'type' => 'model',
                'model' => $paramType,
                'name' => $paramName,
            ];
            return null;
        }

        $this->parameters[$paramName] = [
            'type' => 'class',
            'class' => $paramType,
            'name' => $paramName,
        ];
    }

    protected function parseAssignment(Node\Expr\Assign $node)
    {
        $varName = (string) ($node->var->name ?? '');

        if ($varName) {
            $this->assignments[$varName] = $this->parseExpression($node->expr);
        }
    }

    protected function parseReturn(Node\Stmt\Return_ $node)
    {
        if ($node->expr instanceof Node\Expr\StaticCall || $node->expr instanceof Node\Expr\New_) {
            $className = (string) $node->expr->class;

            if ($this->isResource($className)) {
                $methodName = (string) ($node->expr->name ?? '');
                $this->responses[] = [
                    'type' => 'resource',
                    'resource' => $className,
                    'collection' => $methodName === 'collection',
                    'param' => $this->parseExpression($node->expr->args[0]->value),
                    'code' => 200,
                ];
                return;
            }
        }

        $rawResp = null;
        $statusCode = 200;

        $jsonMethod = $this->searchMethod($node->expr, 'json');
        if ($jsonMethod && count($jsonMethod->args)) {
            $rawResp = $jsonMethod->args[0]->value;
            if (count($jsonMethod->args) > 1) {
                $statusCode = $this->extractValue($jsonMethod->args[1]->value) ?: 200;
            }
        }

        if (!$rawResp) {
            $rawResp = $node->expr;
        }

        $response = $this->parseExpression($rawResp) ?: ['type' => 'value', 'data' => null];
        $response['code'] = $statusCode;

        $this->responses[] = $response;
        return;
    }

    protected function isFormRequest($className)
    {
        return is_subclass_of($className, \Illuminate\Foundation\Http\FormRequest::class);
    }

    protected function isModel($className)
    {
        return is_subclass_of($className, \Illuminate\Database\Eloquent\Model::class);
    }

    protected function isResource($className)
    {
        return is_subclass_of($className, \Illuminate\Http\Resources\Json\Resource::class)
            || is_subclass_of($className, \Illuminate\Http\Resources\Json\JsonResource::class);
    }

    protected function resolveVariable($varName)
    {
        $varName = (string) $varName;

        if (!$varName) {
            return null;
        }

        if (isset($this->assignments[$varName])) {
            return $this->assignments[$varName];
        }

        if (isset($this->parameters[$varName])) {
            return $this->parameters[$varName];
        }

        return null;
    }

    protected function parseExpression(Node\Expr $node)
    {
        if ($node instanceof Node\Expr\ArrayDimFetch) {
            if ($var = $this->resolveVariable($node->var->name ?? '')) {
                return $var;
            }
        }

        if ($node instanceof Node\Scalar || $node instanceof Node\Expr\Array_) {
            return [
                'type' => 'value',
                'data' => $this->extractValue($node),
            ];
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $varNode = $this->findVariable($node);

            if ($varNode && $var = $this->resolveVariable($varNode->name)) {
                if ($var['type'] === 'model' && !($var['paginate'] ?? false)) {
                    $var['paginate'] = !!$this->searchMethod($node, 'paginate');
                }
                return $var;
            }
        }

        if ($node instanceof Node\Expr\Variable) {
            return $this->resolveVariable($node->name ?? '') ?: null;
        }

        $classNode = $this->findClass($node);
        $className = $classNode ? $classNode->class->toString() : null;

        if ($className === null) {
            return null;
        }

        if ($this->isModel($className)) {
            return [
                'type' => 'model',
                'model' => $className,
                'paginate' => !!$this->searchMethod($node, 'paginate'),
            ];
        }

        if ($this->isResource($className)) {
            $methodName = (string) ($node->expr->name ?? '');
            return [
                'type' => 'resource',
                'resource' => $className,
                'collection' => $methodName === 'collection',
                'param' => $this->parseExpression($classNode->args[0]->value),
            ];
        }

        return [
            'type' => 'class',
            'class' => $className,
        ];
    }

    protected function extractValue($node)
    {
        if (!$node) {
            return null;
        }

        if ($node instanceof Node\Scalar\LNumber || $node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Expr\Variable) {
            $var = $this->resolveVariable($node->name ?? '');

            if ($var && $var['type'] === 'value') {
                return $var['data'];
            }
        }

        if ($node instanceof Node\Expr\Array_) {
            return array_reduce($node->items, function($arr, $item) {
                if ($key = $this->extractValue($item->key)) {
                    $arr[$key] = $this->extractValue($item->value);
                }
                return $arr;
            }, []);
        }

        return null;
    }

    protected function findClass($node)
    {
        return $this->findFirst($node, function($node) {
            return $node instanceof Node\Expr\StaticCall
                || $node instanceof Node\Expr\New_;
        });
    }

    protected function findVariable($node)
    {
        return $this->findFirst($node, function($node) {
            return $node instanceof Node\Expr\Variable;
        });
    }

    protected function searchMethod($node, $methodName)
    {
        return $this->findFirst($node, function($node) use ($methodName) {
            $isMethod = $node instanceof Node\Expr\StaticCall
                || $node instanceof Node\Expr\MethodCall;

            return $isMethod && $node->name->toString() === $methodName;
        });
    }
}
