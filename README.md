# CRUD Generator for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/amdadulhaq/crud-generator-laravel.svg?style=flat-square)](https://packagist.org/packages/amdadulhaq/crud-generator-laravel)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/amdadulhaq/crud-generator-laravel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/amdad121/crud-generator-laravel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/amdad121/crud-generator-laravel/lint.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/amdad121/crud-generator-laravel/actions?query=workflow%3A"Fix+Code+Style"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/amdadulhaq/crud-generator-laravel.svg?style=flat-square)](https://packagist.org/packages/amdadulhaq/crud-generator-laravel)

## Overview

The **CRUD Generator** package for Laravel is a command-line tool that helps you quickly create a complete CRUD (Create, Read, Update, Delete) setup for your models, including migration files, controllers, and Blade views. This package streamlines the development process by generating the necessary files with minimal input from the developer.

## Features

-   Generate models with fillable properties.
-   Create migration files with specified fields.
-   Generate controllers with all necessary CRUD methods.
-   Create Blade views for listing, creating, editing, and showing model instances.
-   Automatically add resource routes to `web.php`.

## Requirements

-   PHP >= 8.2
-   Laravel >= 10.x

## Installation

1. **Install the package via Composer**:

    ```bash
    composer require amdadulhaq/crud-generator-laravel
    ```

2. **Register the Service Provider (if not using auto-discovery)**:

    In config/app.php, add the service provider to the providers array:

    ```php
    AmdadulHaq\CRUDGenerator\CrudServiceProvider::class,
    ```

3. **Publish the configuration file (optional)**:

    You can publish the configuration file to customize the package behavior:

    ```bash
    php artisan vendor:publish --provider="AmdadulHaq\CRUDGenerator\CrudServiceProvider"
    ```

    This will create a crud_generator.php file in your config directory.

## Usage

To generate CRUD resources, use the following Artisan command:

```bash
php artisan make:crud {name} {--fields=}
```

### Parameters

{name} (optional): The name of the model you want to create.
{--fields=} (optional): A comma-separated list of fields for the model, in the format fieldName:fieldType.

### Example

1. **Generate a CRUD setup for a Post model with fields: title (string) and content (text)**:

    ```bash
    php artisan make:crud Post --fields="title:string,content:text"
    ```

2. **If you do not provide the --fields option, you will be prompted to enter fields interactively**:

    ```bash
    php artisan make:crud
    ```

    You will then enter model and field names and types one by one until you finish.

## Generated Files

The command will create the following files:

**Model**: `app/Models/Post.php`

**Migration**: `database/migrations/YYYY_MM_DD_HHMMSS_create_posts_table.php`

**Controller**: `app/Http/Controllers/PostController.php`

**Blade Views**:

`resources/views/posts/index.blade.php`

`resources/views/posts/create.blade.php`

`resources/views/posts/edit.blade.php`

`resources/views/posts/show.blade.php`

**Resource Routes**: Automatically added to `routes/web.php`

## Configuration

You can customize the behavior of the CRUD generator by modifying the `config/crud_generator.php` file. The following options are available:

`generate_model`: Generate a model (default: true).

`generate_migration`: Generate a migration (default: true).

`generate_controller`: Generate a controller (default: true).

`generate_blade`: Generate Blade views (default: true).

`generate_route`: Add resource routes (default: true).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Contributing

Contributions are welcome! If you find any issues or have suggestions for improvements, please open an issue or submit a pull request.
