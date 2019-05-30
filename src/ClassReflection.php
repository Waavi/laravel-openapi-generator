<?php

namespace Waavi\LaravelOpenApiGenerator;

use Doctrine\Common\Annotations\TokenParser;
use ReflectionClass;

class ClassReflection
{
    protected $reflection;

    protected $content;

    protected $namespace;

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

        $code = $this->getFileSlice(
            $methodRef->getStartLine(),
            $methodRef->getEndLine()
        );

        $resource = $this->findClassUsage($code, '[a-zA-Z0-9]+Resource') ?: null;

        return [
            // TODO extract method description
            'description' => $methodRef->getDocComment(),
            'returns' => $resource,
        ];
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

    protected function findClassUsage($code, $classRegex)
    {
        $methodRegex = '[a-zA-Z0-9_]+';

        // Attempt to find one of:
        // - "return new UserResource(...);"
        // - "return UserResource::collection()"
        $regex = "/(new +)?({$classRegex})(::({$methodRegex}))?/";

        preg_match($regex, $code, $matched);

        if (!$matched || !count($matched)) {
            return null;
        }

        return [
            'type' => 'class',
            'class' => $this->resolveClass($matched[2]),
            'instance' => trim($matched[1]) === 'new',
            'method' => $matched[4] ?? null,
        ];
    }
}
