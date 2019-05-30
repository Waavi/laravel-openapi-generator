<?php

namespace Waavi\LaravelOpenApiGenerator;

use Closure;
use Illuminate\Database\Eloquent\Factory as EloquentFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\Yaml\Yaml;
use ReflectionClass;
use ReflectionException;

class DocumentBuilder
{
    const YAML_INLINE_LEVEL = 16;

    protected $models = [];

    protected $ignoreMethods = [
        'HEAD',
        'OPTIONS',
    ];

    protected $ignoreUris = [
        '{fallbackPlaceholder}',
    ];

    protected $reflectionCache = [];

    protected $endpoints = [];

    protected $definitions = [];

    protected $document;

    /**
     * @param Router $router
     */
    public function __construct()
    {
        $this->models = $this->getFactoryModels();
    }

    protected function getFactoryModels()
    {
        $factory = app(EloquentFactory::class);

        return array_keys(
            $this->extractProperty($factory, 'definitions') ?: []
        );
    }

    // Helper to access an object's protected property
    protected function extractProperty($object, $property)
    {
        try {
            $objReflection = new ReflectionClass($object);
        } catch (ReflectionException $e) {
            return null;
        }

        $propReflection = $objReflection->getProperty($property);
        $propReflection->setAccessible(true);

        return $propReflection->getValue($object);
    }

    public function addRoute(Route $route)
    {
        foreach ($this->generateEndpoints($route) as $endpoint) {
            $this->endpoints[] = $endpoint;
        }

        return $this;
    }

    public function addRoutes($routes)
    {
        foreach ($routes as $route) {
            $this->addRoute($route);
        }

        return $this;
    }

    /**
     * @param $class
     * @return ClassReflection
     */
    protected function reflection($class)
    {
        if (!isset($this->reflectionCache[$class])) {
            $this->reflectionCache[$class] = new ClassReflection($class);
        }
        return $this->reflectionCache[$class];
    }

    protected function generateEndpoints(Route $route)
    {
        $endpoints = [];

        if (in_array($route->uri(), $this->ignoreUris)) {
            return $endpoints;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, $this->ignoreMethods)) {
                continue;
            }

            list($className, $handlerName) = explode('@', $route->getActionName());

            $handler = $this->reflection($className)->getMethodData($handlerName);

            $endpoints[] = [
                'method' => $method,
                'route' => $route,
                'handler' => $handler,
            ];
        }

        return $endpoints;
    }

    protected function getMiddleware($route)
    {
        return collect($route->gatherMiddleware())->map(function ($middleware) {
            return $middleware instanceof Closure ? 'Closure' : $middleware;
        })->all();
    }

    protected function renderResponseSchema($className, $isCollection)
    {
        $definitionName = class_basename($className) . ($isCollection ? 'Collection' : '');
        $reference = [ '$ref' => "#/definitions/{$definitionName}" ];


        if (!isset($this->definitions[$definitionName])) {
            $example = $this->generateResourceExample($className, $isCollection);

            $this->definitions[$definitionName] = [
                'type' => 'object',
                'example' => $example,
            ];
        }

        return $reference;
    }

    protected function renderEndpoint($route, $handler)
    {
        $middleware = $this->getMiddleware($route);

        $tags = [];
        $schema = [ 'type' => 'object' ];

        $prefix = $route->getPrefix();
        if ($prefix) {
            $tags = [ trim($prefix, '/') ];
        }

        $returns = $handler['returns'];
        if ($returns && $returns['type'] === 'class') {
            $isCollection = !$returns['instance'] && $returns['method'] === 'collection';
            $schema = $this->renderResponseSchema($returns['class'], $isCollection);
        }

        return [
            'summary' => 'Summary goes here',
            'description' => $handler['description'] ?: '',
            'security' => [
                [ 'Bearer' => [] ],
            ],
            'tags' => $tags,
            'operationId' => uniqid(),
            'consumes' => [ 'application/json' ],
            'produces' => [ 'application/json' ],
            'responses' => [
                '200' => [
                    'description' => 'OK',
                    'schema' => $schema,
                ]
            ],
            'parameters' => collect($route->parameterNames())->map(function($param) {
                return [ 'in' => 'path', 'name' => $param, 'required' => true, 'type' => 'string' ];
            })->all(),
        ];
    }

    public function build()
    {
        $endpoints = collect($this->endpoints)->groupBy(function($endpoint) {
            return '/' . trim($endpoint['route']->uri(), '/');
        })->map(function($endpoints) {
            return $endpoints->keyBy(function($endpoint) {
                return strtolower($endpoint['method']);
            })->map(function($endpoint) {
                return $this->renderEndpoint($endpoint['route'], $endpoint['handler']);
            })->all();
        })->all();

        $this->document = [
            'swagger' => '2.0',
            'info' => [
                'version' => '1.0.0',
                'title' => env('APP_NAME') ?: '',
                'description' => '',
            ],
            'securityDefinitions' => [
                'Bearer' => [
                    'type' => 'apiKey',
                    'name' => 'Authorization',
                    'in' => 'header',
                ]
            ],
            'tags' => [],
            'paths' => $endpoints,
            'definitions' => $this->definitions,
        ];

        return $this;
    }

    protected function findResourceModel($resource)
    {
        $resourceName = class_basename($resource);

        foreach ($this->models as $model) {
            if (strpos($resourceName, class_basename($model))) {
                return $model;
            }
        }

        return null;
    }

    public function generateResourceExample($resource, $isCollection = false)
    {
        $model = $this->findResourceModel($resource);

        if (!$model) {
            return null;
        }

        // Build a collection of resources
        if ($isCollection) {
            return $resource::collection(
                factory($model, 2)->create()
            )->toArray(new Request);
        }

        // Build a single resource
        return (new $resource(
            factory($model)->create()
        ))->toArray(new Request);
    }

    public function toJson()
    {
        return json_encode($this->document, JSON_PRETTY_PRINT);
    }

    public function toYaml()
    {
        return Yaml::dump($this->document, self::YAML_INLINE_LEVEL);
    }
}
