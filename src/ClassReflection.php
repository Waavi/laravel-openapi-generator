<?php

namespace Waavi\LaravelOpenApiGenerator;

use Doctrine\Common\Annotations\TokenParser;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionMethod;

class ClassReflection
{
    /** @var ReflectionClass */
    protected $reflection;

    /** @var string */
    protected $content;

    /** @var string */
    protected $namespace;

    /** @var array */
    protected $aliases;

    public function __construct($class)
    {
        $this->reflection = new ReflectionClass($class);

        $this->content = file_get_contents(
            $this->reflection->getFileName()
        );

        $parser = new TokenParser($this->content);

        $this->namespace = $parser->parseNamespace();

        $this->aliases = $parser->parseUseStatements($this->namespace);
    }

    protected function resolveClass($alias)
    {
        $pos = strpos($alias, '\\');

        if ($pos === 0) {
            return substr($alias, 1); // Already fully qualified
        }

        if ($pos === false) {
            $first = $alias;
            $next = '';
        } else {
            $first = substr($alias, 0, $pos);
            $next = substr($alias, $pos);
        }

        $first = strtolower($first);

        if (isset($this->aliases[$first])) {
            return $this->aliases[$first] . $next;
        }

        return $this->namespace . '\\' . $alias;
    }

    public function getMethodData($method)
    {
        $methodRef = $this->reflection->getMethod($method);

        $description = $this->getComment($methodRef);

        return [
            'summary' => explode("\n", $description)[0],
            'description' => $description,
            'formRequest' => $this->findFormRequestParam($methodRef) ?: null,
            'resource' => $this->findClassUsage($methodRef, '[a-zA-Z0-9]+Resource') ?: null,
        ];
    }

    protected function findFormRequestParam(ReflectionMethod $methodRef)
    {
        foreach ($methodRef->getParameters() as $paramRef) {
            $classRef = $paramRef->getClass();

            if ($classRef && $classRef->isSubclassOf(FormRequest::class)) {
                return $classRef->getName();
            }
        }

        return null;
    }

    protected function getComment(ReflectionMethod $methodRef)
    {
        $docComment = $methodRef->getDocComment();

        // Clean up comment punctuation
        $comment = collect(explode("\n", $docComment))
            ->map(function($line) {
                return trim($line, "\t\n /*");
            })
            // Remove lines with @param, @return, etc. Only keep comments.
            ->filter(function($line) {
                return !strlen($line) || $line[0] !== '@';
            })
            ->implode("\n");

        // Remove empty starting and ending lines.
        return trim($comment);
    }

    protected function getFileSlice($startLine, $endLine)
    {
        // startLine - 1, otherwise you won't get the function() block
        return implode('', array_slice(
            explode("\n", $this->content),
            $startLine - 1,
            $endLine
        ));
    }

    protected function findClassUsage(ReflectionMethod $methodRef, $classRegex)
    {
        $code = $this->getFileSlice(
            $methodRef->getStartLine(),
            $methodRef->getEndLine()
        );

        $methodRegex = '[a-zA-Z0-9_]+';

        // Attempt to find one of:
        // - "return new UserResource(...);"
        // - "return UserResource::collection()"
        $regex = "/(new +)?({$classRegex})(::({$methodRegex}))?/";

        preg_match($regex, $code, $matched);

        if ($matched && count($matched)) {
            $hasNew = trim($matched[1]) === 'new';
            $className = $this->resolveClass($matched[2]);
            $methodName = $matched[4] ?? null;

            if ($hasNew) {
                return $className;
            }

            if ($methodName) {
                return "{$className}::{$methodName}";
            }
        }

        return null;
    }
}
