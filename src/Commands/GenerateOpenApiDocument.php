<?php

namespace Waavi\LaravelOpenApiGenerator\Commands;

use Waavi\LaravelOpenApiGenerator\DocumentBuilder;
use Illuminate\Routing\Router;
use Illuminate\Console\Command;

class GenerateOpenApiDocument extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'openapi:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates the OpenApi document';

    protected $router;

    protected $builder;

    /**
     * Create a new route command instance.
     *
     * @param  \Illuminate\Routing\Router $router
     * @param DocumentBuilder $builder
     */
    public function __construct(Router $router, DocumentBuilder $builder)
    {
        parent::__construct();

        $this->router = $router;
        $this->builder = $builder;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $document = $this->builder->addRoutes(
            $this->router->getRoutes()
        )->build();

        if ((bool) config('openapi-generator.write')) {
            $this->writeToFile($document);
        }

        if ((bool) config('openapi-generator.print')) {
            $this->print($document);
        }
    }

    private function writeToFile($document)
    {
        $this->info('Generating document...');

        $outputFile = config('openapi-generator.output', base_path('openapi.yaml'));
        $extension = pathinfo($outputFile, PATHINFO_EXTENSION);

        $document = $this->formatDocument($document, $extension);

        file_put_contents($outputFile, $document);

        $this->info('Generating document... done');
    }

    private function print($document)
    {
        echo $this->formatDocument($document);
    }

    private function formatDocument($document, $extension)
    {
        if ($extension === 'json') {
            return $document->toJson();
        }

        if ($extension === 'yml' || $extension === 'yaml') {
            return $document->toYaml();
        }

        throw new \RuntimeException("Unknown document extension '{$extension}',"
            . " must be either 'json' or 'yaml'");
    }
}
