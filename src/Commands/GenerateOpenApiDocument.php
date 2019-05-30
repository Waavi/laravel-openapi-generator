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
        )->toJson();

        $this->writeToFile($document);
    }

    private function writeToFile($document)
    {
        file_put_contents('/home/sergio/openapi.json', $document);
    }
}
