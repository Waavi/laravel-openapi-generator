<?php

return [
    /**
     * The OpenApi Specification version to use.
     * Accepted values are '2' and '3'.
     */
    'version' => '2',

    /**
     * Print the generated OpenApi document to stdout.
     */
    'print' => false,

    /**
     * Save the generated OpenApi document to a file.
     */
    'write' => true,

    /**
     * The OpenApi document to generate.
     *
     * Should be either a JSON or YAML file.
     */
    'output' => base_path('openapi.yaml'),

    /**
     * Wether models instanced from factories to create
     * endpoints schemas should be saved to the database.
     *
     * When this option is set to true, models will be
     * created through `factory()->create()` instead of
     * the default `factory()->make()`.
     */
    'create_factories' => false,

    /**
     * Map a known class to a custom output to show when
     * it is returned as a route's output.
     */
    'class_maps' => [
        App\MyClass::class => [],
    ],

    /**
     * Map a known Resource to a Model or custom input.
     *
     * By default resources are matched with a model with
     * configured factories by matching their names.
     *
     * Configuring them here, the model received by a resource
     * can be set explicitly. Also, resources might receive a
     * non-model class, or a value which is not a class.
     */
    'resource_maps' => [
        App\Http\Resources\MyResource::class => MyClass::class,
        App\Http\Resources\OtherResource::class => 'foo',
    ],

    /**
     * Map a custom request or response to endpoints.
     *
     * Endpoints can be identified either by their action
     * method, or by their route name.
     */
    'endpoint_maps' => [
        App\Http\Controllers\MyController::class.'@method' => [
            'request' => ['field' => 'required|string'],
            'response' => App\Http\Resources\MyResource::class,
        ],
        'actionName' => [
            'request' => App\Http\Requests\MyRequest::class,
            'response' => ['success' => true],
        ],
    ],

];
