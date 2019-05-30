<?php

namespace Waavi\LaravelOpenApiGenerator;

use Illuminate\Support\ServiceProvider;
use Waavi\LaravelOpenApiGenerator\Commands\GenerateOpenApiDocument;

class OpenApiGeneratorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/openapi-generator.php' => config_path('openapi-generator.php'),
        ]);

        $this->app->bind('command.openapi', GenerateOpenApiDocument::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateOpenApiDocument::class,
            ]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/openapi-generator.php', 'openapi-generator');
    }
}
