<?php

namespace Waavi\LaravelOpenApiGenerator;

use Illuminate\Database\Eloquent\Factory as EloquentFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\Resource;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

class SchemaBuilder
{
    protected $models = [];

    protected $resourceMaps = [];

    protected $reflectionCache = [];

    public function __construct()
    {
        $this->models = $this->getFactoryModels();
        $this->resourceMaps = config('openapi-generator.resource_maps', []);
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

    // 'action' might have the formats:
    // 'App\Http\Resources\MyResource'
    // 'App\Http\Resources\MyResource::collection'
    // 'App\Whatever\MyClass@method'
    // 'non-class-string'
    // [ 'foo' => 'bar' ]
    public function build($action)
    {
        if (!is_string($action)) {
            return ['type' => 'object', 'example' => $action];
        }

        try {
            $example = null;
            list($class, $method, $static) = $this->parseClassName($action);

            if (is_subclass_of($class, Resource::class) || is_subclass_of($class, JsonResource::class)) {
                $example = $this->buildResourceOutput($class,
                    $static && $method === 'collection'
                );
            }

            if ($example === null) {
                $example = $class;
            }

            return ['type' => 'object', 'example' => $example];
        } catch (ReflectionException $e) {
            return ['type' => 'string', 'example' => 'object'];
        }
    }

    protected function parseClassName($action)
    {
        $parts = explode('@', $action);

        if (count($parts) === 2) {
            return [$parts[0], $parts[1], false];
        }

        $parts = explode('::', $action);

        if (count($parts) === 2) {
            return [$parts[0], $parts[1], true];
        }

        return [$action, null, false];
    }

    public function buildResourceOutput($resource, $isCollection = false)
    {
        $input = $this->buildResourceInput($resource);

        if ($input === null) {
            return null;
        }

        $request = new Request;
        $request->setUserResolver(function($guard = null) {
            $guard = $guard ?: config('auth.defaults.guard');
            $provider = config("auth.guards.{$guard}.provider");
            $model = config("auth.providers.{$provider}.model");

            return $this->buildModel($model);
        });

        // Build a collection of resources
        if ($isCollection) {
            $inputsColl = collect([$input, $input]);
            return $resource::collection($inputsColl)->toArray($request);
        }

        // Build a single resource
        return (new $resource($input))->toArray($request);
    }

    protected function buildModel($model)
    {
        $makeOrCreate = config('openapi-generator.create_factories', false)
            ? 'create'
            : 'make';

        // Make/create the model from a factory. If the 'openapi' state exists,
        // apply it.
        // The user may have defined custom properties, afterMakingState() or
        // afterCreatingState() hooks only for this state, so models can be
        // instanced differently when generating the OpenApi document.
        try {
            return factory($model)->states('openapi')->{$makeOrCreate}();
        } catch (InvalidArgumentException $e) {
            return factory($model)->{$makeOrCreate}();
        }
    }

    protected function buildResourceInput($resource)
    {
        $input = isset($this->resourceMaps[$resource])
            ? $this->resourceMaps[$resource]
            : $this->findResourceModel($resource);

        if ($input === null) {
            return null;
        }

        // The input is the class of a known model which
        // has a factory configured; instance it.
        if (in_array($input, $this->models)) {
            return $this->buildModel($input);
        }

        // The input is not a string. Assume its a value
        // that the resource can receive directly.
        if (!is_string($input)) {
            return $input;
        }

        // Attempt to instance as a class/alias.
        try {
            return app()->make($input);
        } catch (\ReflectionException $e) {
            // Class does not exist, assume its a value
            // that the resource can receive directly.
            return $input;
        }
    }

    protected function findResourceModel($resource)
    {
        $resourceName = class_basename($resource);

        return collect($this->models)->sortByDesc(function($model) {
            return class_basename(strlen($model));
        })->first(function($model) use ($resourceName) {
            return strpos($resourceName, class_basename($model)) !== false;
        });
    }
}
