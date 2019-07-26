<?php

namespace Waavi\LaravelOpenApiGenerator;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use Waavi\LaravelOpenApiGenerator\NodeVisitors\ParseRoutes;

class ControllerParser
{
    protected $parser;

    protected $routes;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    }

    public function parseClass($className)
    {
        return $this->parseFile(
            (new ReflectionClass($className))->getFileName()
        );
    }

    public function parseFile($filePath)
    {
        return $this->parseCode(file_get_contents($filePath));
    }

    public function parseCode($codeStr)
    {
        $ast = $this->parser->parse($codeStr);

        $ast = $this->traverse($ast,
            new NameResolver(null, ['preserveOriginalNames' => true])
        );

        $ast = $this->traverse($ast,
            new ParseRoutes(function($route) {
                $this->routes[] = $route;
            })
        );

        return $this;
    }

    public function getRoutes()
    {
        return $this->routes;
    }

    public function getRoute($method)
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method) {
                return $route;
            }
        }
        return null;
    }

    protected function traverse($ast, $visitor)
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        return $traverser->traverse($ast);
    }
}
