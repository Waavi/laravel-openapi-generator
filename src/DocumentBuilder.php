<?php

namespace Waavi\LaravelOpenApiGenerator;

use Closure;
use Illuminate\Database\Eloquent\Factory as EloquentFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\Resource;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\Yaml\Yaml;
use ReflectionClass;
use ReflectionException;

class DocumentBuilder
{
    const YAML_INLINE_LEVEL = 16;

    const YAML_INDENT_SPACES = 4;

    protected $schemaBuilder;

    protected $endpointMaps = [];

    protected $ignoreMethods = ['HEAD', 'OPTIONS'];

    protected $ignoreUris = ['{fallbackPlaceholder}'];

    protected $reflectionCache = [];

    protected $endpoints = [];

    protected $definitions = [];

    protected $document;

    public function __construct()
    {
        $this->schemaBuilder = new SchemaBuilder;
        $this->endpointMaps = config('openapi-generator.endpoint_maps', []);
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
     * @return ControllerParser
     */
    protected function reflection($class)
    {
        if (!isset($this->reflectionCache[$class])) {
            $this->reflectionCache[$class] = (new ControllerParser)->parseClass($class);
        }
        return $this->reflectionCache[$class];
    }

    protected function parseRouteHandler($route)
    {
        $arr = explode('@', $route->getActionName());

        return [$arr[0], $arr[1] ?? null];
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

            $endpoints[] = $this->generateEndpoint($method, $route);
        }

        return $endpoints;
    }

    protected function generateEndpoint($method, Route $route)
    {
        $endpoint = [
            'id' => trim($route->getName() ?: $route->getActionName(), '\\'),
            'tags' => $this->buildTags($route),
            'middleware' => $this->getMiddleware($route),
            'httpMethod' => $method,
            'route' => $route,
            'summary' => '',
            'description' => '',
            'parameters' => [],
            'responses' => [],
            'formRequest' => null,
        ];

        list($className, $methodName) = $this->parseRouteHandler($route);

        if ($className === 'Closure') {
            return $endpoint;
        }

        $handler = $this->reflection($className)->getRoute($methodName);

        return $handler + $endpoint;
    }

    protected function getMiddleware($route)
    {
        return collect($route->gatherMiddleware())->map(function ($middleware) {
            return $middleware instanceof Closure ? 'Closure' : $middleware;
        })->all();
    }

    protected function buildTags($route)
    {
        $tags = [];

        if ($prefix = $route->getPrefix()) {
            $tags = [trim($prefix, '/')];
        }

        return $tags;
    }

    protected function renderEndpoint($endpoint)
    {
        $parameters = array_merge(
            $this->buildPathParams($endpoint),
            $this->buildQueryParams($endpoint)
        );

        $schema = $this->renderSchema($endpoint);

        return [
            'summary' => $endpoint['summary'] ?: '',
            'description' => $endpoint['description'] ?: '',
            'security' => [
                ['Bearer' => []],
            ],
            'tags' => $endpoint['tags'],
            'operationId' => $endpoint['id'],
            'consumes' => ['application/json'],
            'produces' => ['application/json'],
            'responses' => [
                '200' => [
                    'description' => 'OK',
                    'schema' => $schema,
                ]
            ],
            'parameters' => $parameters,
        ];
    }

    protected function renderSchema($endpoint)
    {
        $endpointMap = $this->endpointMaps[$endpoint['id']] ?? null;

        $resource = collect($endpoint['responses'])->filter(function($resp) {
            return $resp['code'] === 200 || $resp['code'] === 201;
        })->sortBy(function($resp) {
            if ($resp['type'] === 'resource') return 1;
            if ($resp['type'] === 'value') return 2;
            return 3;
        })->first();

        $response = $endpointMap['response'] ?? $resource ?: null;

        if (($response['type'] ?? null) !== 'resource') {
            return $this->schemaBuilder->build($response);
        }

        $definition = $response['resource'];

        if ($response['collection']) {
            $definition .= '::collection';
        }

        $schemaRef = ['$ref' => '#/definitions/'.urlencode($definition)];

        if (isset($this->definitions[$definition])) {
            return $schemaRef;
        }

        $schema = $this->schemaBuilder->build($response);

        $this->definitions[$definition] = $schema;

        return $schemaRef;
    }

    protected function buildPathParams($endpoint)
    {
        return collect($endpoint['route']->parameterNames())->map(function($param) use ($endpoint) {
            $type = 'string';
            $description = '';

            $model = $endpoint['parameters'][$param]['model'] ?? null;

            if ($model) {
                $instance = new $model;
                if ($instance->getKeyType() === 'int') {
                    $type = 'integer';
                }
                $description = class_basename($model)." model {$instance->getRouteKeyName()}";
            }

            return [
                'in' => 'path',
                'name' => $param,
                'required' => true,
                'type' => $type,
                'description' => $description,
            ];
        })->all();
    }

    protected function buildQueryParams($endpoint)
    {
        return $this->normalizeRules($endpoint)
            ->map(function($paramRules, $paramKey) {
                $required = false;
                $type = 'string';
                $isArray = false;
                $description = [];

                foreach ($paramRules as $ruleStr) {
                    list($rule, $params) = $this->parseRuleString($ruleStr);

                    if ($rule === 'required' || $rule === 'present') {
                        $required = true;
                    }
                    if ($rule === 'array') {
                        $description[] = 'Array field, can be set several times.';
                        $isArray = true;
                    }
                    if ($rule === 'integer') {
                        $type = 'integer';
                    }
                    if ($rule === 'numeric') {
                        $type = 'number';
                    }
                    if ($rule === 'boolean') {
                        $type = 'boolean';
                    }
                    if ($rule === 'nullable') {
                        $description[] = 'Field may be null.';
                    }
                    if ($rule === 'required_if') {
                        $description[] = "Required when {$params[0]}={$params[1]}.";
                    }
                    if ($rule === 'in') {
                        $description[] = 'One of ' . $this->listRuleParams($params) . '.';
                    }
                    if ($rule === 'between') {
                        $description[] = 'Between ' . $this->listRuleParams($params, ' and ') . '.';
                    }
                    if ($rule === 'unique') {
                        $description[] = 'Must be unique.';
                    }
                }

                $param = [
                    'in' => 'query',
                    'name' => $paramKey,
                    'required' => $required,
                    'description' => implode('<br>', $description),
                ];

                if ($isArray) {
                    $param['type'] = 'array';
                    $param['items'] = ['type' => $type];
                } else {
                    $param['type'] = $type;
                }

                return $param;
            })
            ->values()
            ->all();
    }

    protected function listRuleParams($params, $glue = ', ', $quote = '"')
    {
        return collect($params)->map(function($param) use ($quote) {
            $param = trim($param, "'\"");
            return $quote.$param.$quote;
        })->implode($glue);
    }

    protected function buildFormRequest($requestClass, $endpoint)
    {
        $route = clone $endpoint['route'];

        $request = (new $requestClass)->setRouteResolver(function() use ($route) {
            return $route;
        });

        $route->bind($request);

        foreach ($endpoint['parameters'] as $param) {
            if ($param['type'] === 'model') {
                $route->setParameter($param['name'], $this->buildModel($param['model']));
            }
        }

        return $request;
    }

    protected function buildModel($model)
    {
        $shouldCreate = config('openapi-generator.create_factories', false);
        $makeOrCreate = $shouldCreate ? 'create' : 'make';

        // Make/create the model from a factory. If the 'openapi' state exists,
        // apply it.
        // The user may have defined custom properties, afterMakingState() or
        // afterCreatingState() hooks only for this state, so models can be
        // instanced differently when generating the OpenApi document.
        try {
            return factory($model)->states('openapi')->{$makeOrCreate}();
        } catch (\InvalidArgumentException $e) {
            return factory($model)->{$makeOrCreate}();
        }
    }

    protected function normalizeRules($endpoint)
    {
        $endpointMap = $this->endpointMaps[$endpoint['id']] ?? null;

        $request = $endpointMap['request'] ?? $endpoint['formRequest'];

        $rules = [];

        if (is_string($request) && is_subclass_of($request, FormRequest::class)) {
            try {
                $rules = $this->buildFormRequest($request, $endpoint)->rules();
            } catch (\Exception $e) {
                echo "Error: cannot build {$request}: {$e->getMessage()}\n";
            }
        }

        if (!is_string($request)) {
            $rules = $request;
        }

        return collect($rules)
            ->map(function ($paramRules) {
                return is_array($paramRules)
                    ? $paramRules
                    : explode('|', $paramRules);
            })
            ->map(function ($paramRules) {
                return collect($paramRules)
                    ->map(function ($rules) { return (string) $rules; })
                    ->all();
            });
    }


    protected function parseRuleString($rule)
    {
        $arr = explode(':', $rule);
        return [$arr[0], explode(',', $arr[1] ?? '')];
    }

    public function build()
    {
        $endpoints = collect($this->endpoints)->groupBy(function($endpoint) {
            return '/' . trim($endpoint['route']->uri(), '/');
        })->map(function($endpoints) {
            return $endpoints->keyBy(function($endpoint) {
                return strtolower($endpoint['httpMethod']);
            })->map(function($endpoint) {
                return $this->renderEndpoint($endpoint);
            })->all();
        })->all();

        $this->document = [
            'swagger' => '2.0',
            'info' => [
                'version' => env('APP_VERSION') ?: '1.0.0',
                'title' => config('app.name') ?: '',
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

    public function toJson()
    {
        return json_encode($this->document, JSON_PRETTY_PRINT);
    }

    public function toYaml()
    {
        return Yaml::dump(
            $this->document,
            self::YAML_INLINE_LEVEL,
            self::YAML_INDENT_SPACES,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
        );
    }
}
