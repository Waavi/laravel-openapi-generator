<?php

namespace Waavi\LaravelOpenApiGenerator;

use Illuminate\Database\Eloquent\Factory as EloquentFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\Resource;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

class SchemaBuilder
{
    protected $models = [];

    protected $classMaps = [];

    protected $resourceMaps = [];

    public function __construct()
    {
        $this->models = $this->getFactoryModels();
        $this->classMaps = config('openapi-generator.class_maps', []);
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

    public function build($response)
    {
        if (!$response) {
            return ['type' => 'object', 'example' => []];
        }

        if (!isset($response['type'])) {
            return ['type' => 'object', 'example' => $response];
        }

        if ($response['type'] === 'value') {
            return ['type' => 'object', 'example' => $response['data']];
        }

        if ($response['type'] === 'class') {
            $class = $response['class'];

            if (isset($this->classMaps[$class])) {
                $data = $this->classMaps[$class];
            } else {
                $data = $response['class'];
            }

            return ['type' => 'object', 'example' => $data];
        }

        if ($response['type'] === 'resource') {
            $resource = $response['resource'];

            try {
                if (!$input = $this->buildResourceInput($response)) {
                    echo "Error: cannot build resource '{$resource}'\n";
                    echo json_encode($response, JSON_PRETTY_PRINT) . "\n";
                    return ['type' => 'object', 'example' => $resource];
                }

                $data = $this->buildResourceResponse($resource, $input,
                    $response['collection'],
                    $response['param']['paginate'] ?? false
                );

                return ['type' => 'object', 'example' => $data];
            } catch (\Exception $e) {
                echo "Error: cannot instance resource '{$resource}'\n";
                echo get_class($e).": {$e->getMessage()}\n";
                return ['type' => 'object', 'example' => $resource];
            }
        }

        return ['type' => 'object', 'example' => $response];
    }

    public function buildResourceResponse($resource, $input, $isCollection, $isPaginated)
    {
        $request = (new Request)->setUserResolver(function($guard = null) {
            $guard = $guard ?: config('auth.defaults.guard');
            $provider = config("auth.guards.{$guard}.provider");
            $model = config("auth.providers.{$provider}.model");

            return $this->buildModel($model);
        });

        if ($isCollection) {
            // Build a resource collection
            $inputs = collect([$input, $input]);

            // Build pagination
            if ($isPaginated) {
                $inputs = new LengthAwarePaginator($inputs, 2, 15);
            }

            $resp = $resource::collection($inputs)->toResponse($request);
        } else {
            // Build a single resource
            $resp = (new $resource($input))->toResponse($request);
        }

        return $resp->getData($assoc = true);
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
        } catch (InvalidArgumentException $e) {
            return factory($model)->{$makeOrCreate}();
        }
    }

    protected function buildResourceInput($response)
    {
        $resource = $response['resource'];
        $paramType = $response['param']['type'] ?? null;

        if (isset($this->resourceMaps[$resource])) {
            $input = $this->resourceMaps[$resource];
        } else if ($model = $this->findResourceModel($resource)) {
            $input = $model;
        } else if ($paramType === 'model') {
            $input = $response['param']['model'];
        } else if ($paramType === 'class') {
            $input = $response['param']['class'];
        } else if ($paramType === 'value') {
            $input = $response['param']['data'];
        } else {
            $input = null;
        }

        echo "{$resource} => {$input}\n";

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
            echo "Error: cannot make class '{$input}'\n";

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
