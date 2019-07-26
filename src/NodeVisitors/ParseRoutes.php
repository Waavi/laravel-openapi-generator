<?php

namespace Waavi\LaravelOpenApiGenerator\NodeVisitors;

use PhpParser\Node;
use PhpParser\NodeTraverser;

class ParseRoutes extends BaseNodeVisitor
{
    protected $namespace;

    protected $class;

    protected $callback;

    public function __construct($callback = null)
    {
        $this->callback = $callback;
    }

    public function enterNode(Node $node) {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = (string) $node->name;
        }

        if ($node instanceof Node\Stmt\Class_) {
            $this->class = (string) $node->name;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->traverse($node, new ParseRouteMethod(function($route) {
                ($this->callback)([
                    'class' => "{$this->namespace}\\{$this->class}",
                ] + $route);
            }));
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }
    }
}
