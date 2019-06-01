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

        $filename = config('openapi-generator.filename', 'openapi');
        $extension = config('openapi-generator.format', 'yaml');
        $output = config('openapi-generator.output', base_path());

        $document = $this->formatDocument($document);

        file_put_contents("{$output}/{$filename}.{$extension}", $document);

        $this->info('Generating document... done');
    }

    private function print($document)
    {
        echo $this->formatDocument($document);
    }

    private function formatDocument($document)
    {
        if (config('format') === 'json') {
            $document = $document->toJson();
        } else {
            $document = $document->toYaml();
        }

        return $document;
    }
}
