<?php

namespace AmdadulHaq\CRUDGenerator;

use Illuminate\Support\ServiceProvider;

class CrudServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Load package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/crud_generator.php', 'crud_generator');

        // Register commands
        $this->commands([
            Commands\MakeCrud::class,
        ]);
    }

    public function boot()
    {
        // Optionally publish config files, views, etc.
        $this->publishes([
            __DIR__.'/config/config.php' => config_path('crud_generator.php'),
        ]);
    }
}
