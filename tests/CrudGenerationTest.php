<?php

declare(strict_types=1);

use AmdadulHaq\CRUDGenerator\Commands\MakeCrud;
use Illuminate\Support\Facades\Artisan;

it('can generate crud with model name only', function (): void {
    Artisan::call('make:crud', ['name' => 'TestPost', '--fields' => 'title:string,content:text']);

    expect($this->app->basePath('app/Models/TestPost.php'))->toBeFile();
    expect($this->app->basePath('app/Http/Controllers/TestPostController.php'))->toBeFile();
    expect($this->app->basePath('resources/views/test_post/index.blade.php'))->toBeFile();
});

it('adds fillable property to model', function (): void {
    Artisan::call('make:crud', ['name' => 'TestPost', '--fields' => 'title:string']);

    $modelPath = $this->app->basePath('app/Models/TestPost.php');

    if (file_exists($modelPath)) {
        $modelContent = file_get_contents($modelPath);
        expect($modelContent)->toContain('protected $fillable');
        expect($modelContent)->toContain("'title'");
    } else {
        $this->markTestSkipped('Model file not created');
    }
});

it('creates controller with crud methods', function (): void {
    Artisan::call('make:crud', ['name' => 'TestPost', '--fields' => 'title:string']);

    $controllerPath = $this->app->basePath('app/Http/Controllers/TestPostController.php');

    if (file_exists($controllerPath)) {
        $controllerContent = file_get_contents($controllerPath);
        expect($controllerContent)->toContain('public function index()');
        expect($controllerContent)->toContain('public function store(');
    } else {
        $this->markTestSkipped('Controller file not created');
    }
});

it('parses fields correctly', function (): void {
    $command = new MakeCrud;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('parseFields');

    $fields = 'title:string,content:text,author_id:integer';
    $parsed = $method->invoke($command, $fields);

    expect($parsed)->toBeArray();
    expect($parsed)->toHaveCount(3);
    expect($parsed[0])->toMatchArray(['name' => 'title', 'type' => 'string']);
    expect($parsed[1])->toMatchArray(['name' => 'content', 'type' => 'text']);
    expect($parsed[2])->toMatchArray(['name' => 'author_id', 'type' => 'integer']);
});

it('generates migration fields correctly', function (): void {
    $command = new MakeCrud;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('generateMigrationFields');

    $fields = 'title:string,content:text';
    $result = $method->invoke($command, $fields);

    expect($result)->toContain('$table->string(\'title\')');
    expect($result)->toContain('$table->text(\'content\')');
});

it('generates validation rules based on field types', function (): void {
    $command = new MakeCrud;
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('getValidationRules');

    $fields = [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'content', 'type' => 'text'],
        ['name' => 'published', 'type' => 'integer'],
    ];

    $rules = $method->invoke($command, $fields);

    expect($rules)->toBeArray();
    expect($rules['title'])->toBe('required|string|max:255');
    expect($rules['content'])->toBe('required');
    expect($rules['published'])->toBe('required|integer');
});
