<?php

declare(strict_types=1);

namespace AmdadulHaq\CRUDGenerator\Tests;

use AmdadulHaq\CRUDGenerator\CrudServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [
            CrudServiceProvider::class,
        ];
    }
}
