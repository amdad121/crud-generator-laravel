<?php

namespace AmdadulHaq\CRUDGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class MakeCrud extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:crud {name?} {--fields=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a model, migration, controller, and Blade views with specified fields for CRUD operations';

    // Valid column types
    protected array $validColumnTypes = [
        'string',
        'text',
        'integer',
        'bigInteger',
        'smallInteger',
        'tinyInteger',
        'boolean',
        'date',
        'dateTime',
        'timestamp',
        'decimal',
        'float',
        'double',
        'json',
        'jsonb',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Prompt for the model name if not provided
        $name = $this->argument('name') ?? text(
            label: 'Enter the model name',
            validate: ['name' => 'required|max:255|unique:users']
        );

        // Check if fields were provided via the option, otherwise prompt for fields interactively
        $fields = $this->option('fields') ?: $this->askForFields();

        if (config('make_crud.generate_migration', true)) {
            // Convert fields to migration-compatible format
            $migrationFields = $this->generateMigrationFields($fields);
        }

        if (config('make_crud.generate_model', true)) {
            // Create the model
            $this->call('make:model', [
                'name' => $name,
                '--migration' => true,
            ]);

            // Add fillable property to model
            $this->addFillableToModel($name, $fields);
        }

        if (config('make_crud.generate_migration', true)) {
            // Modify the migration file with the specified fields
            $this->updateMigrationFile($name, $migrationFields);
        }

        if (config('make_crud.generate_controller', true)) {
            // Create the controller
            $this->call('make:controller', [
                'name' => "{$name}Controller",
                '--resource' => false,
            ]);

            // Update the generated controller with dynamic methods
            $this->updateController($name, $fields);
        }

        if (config('make_crud.generate_blade', true)) {
            // Create Blade views
            $this->createBladeViews($name, $fields);
        }

        if (config('make_crud.generate_route', true)) {
            // Add resource route to web routes
            $this->addResourceRoute($name);
        }

        $this->info("CRUD operations for {$name} created successfully. now you can view ".url(Str::pluralStudly(Str::snake($name))));
    }

    // Interactive prompt to ask for fields if not provided
    protected function askForFields(): string
    {
        $fields = [];
        while (true) {
            $fieldName = text('Enter a field name or leave blank to finish');
            if (empty($fieldName)) {
                break;
            }

            // Prompt to select the field type from the options
            $fieldType = select('Select field type', $this->validColumnTypes);

            // Validate and add the field
            if ($this->validateField($fieldName, $fieldType)) {
                $fields[] = "{$fieldName}:{$fieldType}";
            }
        }

        return implode(',', $fields);
    }

    // Validate the field type
    protected function validateField($fieldName, $fieldType): bool
    {
        $fieldName = trim($fieldName);
        $fieldType = trim($fieldType);

        if (! in_array($fieldType, $this->validColumnTypes)) {
            $this->error("Invalid field type '{$fieldType}'. Valid types are: ".implode(', ', $this->validColumnTypes));

            return false;
        }

        return true;
    }

    // Convert the fields string to migration fields
    protected function generateMigrationFields($fields): string
    {
        $migrationFields = '';

        if ($fields) {
            $fieldArray = explode(',', $fields);

            foreach ($fieldArray as $field) {
                $fieldParts = explode(':', $field);
                $fieldName = trim($fieldParts[0]);
                $fieldType = trim($fieldParts[1] ?? 'string');
                $migrationFields .= "\$table->{$fieldType}('{$fieldName}');\n            ";
            }
        }

        return $migrationFields;
    }

    // Update the generated migration file with the specified fields
    protected function updateMigrationFile($name, $migrationFields): void
    {
        $className = Str::pluralStudly($name);
        $timestamp = now()->format('Y_m_d_His');
        $migrationFile = database_path("migrations/{$timestamp}_create_".Str::snake($className).'_table.php');

        if (file_exists($migrationFile)) {
            $migrationContent = file_get_contents($migrationFile);
            $migrationContent = str_replace(
                '$table->timestamps();',
                rtrim($migrationFields)."\n            ".'$table->timestamps();',
                $migrationContent
            );
            file_put_contents($migrationFile, $migrationContent);
        }

        $tableName = Str::snake($className);
        if (! Schema::hasTable($tableName)) {
            // Run the migration
            $this->runMigration($migrationFile);
        } else {
            $this->info("Table '{$tableName}' already exists. Migration will not run.");
        }
    }

    protected function runMigration($migrationFile)
    {
        $this->info('Running migration...');

        // Call the artisan migrate command
        Artisan::call('migrate', [
            '--path' => 'database/migrations/'.basename($migrationFile),
            '--quiet' => true, // Suppress output if you want
        ]);

        $this->info('Migration ran successfully.');
    }

    // Add fillable property to the model
    protected function addFillableToModel($name, $fields): void
    {
        $modelFile = app_path("Models/{$name}.php");

        if (file_exists($modelFile)) {
            // Generate fillable fields
            $fillableFields = implode("', '", array_map(function ($field) {
                return trim(explode(':', $field)[0]);
            }, explode(',', $fields)));

            $modelContent = file_get_contents($modelFile);

            // Check if fillable property already exists
            if (! preg_match('/protected\s+\$fillable\s*=\s*\[/', $modelContent)) {
                // Insert fillable property immediately after the class declaration
                $modelContent = preg_replace(
                    '/class\s+'.preg_quote($name, '/').'\s*extends\s+Model\s*{/',
                    'class '.$name.' extends Model {'."\n\n    protected \$fillable = ['{$fillableFields}'];\n",
                    $modelContent
                );
                file_put_contents($modelFile, $modelContent);
            }
        }
    }

    // Create Blade views for CRUD operations
    protected function createBladeViews($name, $fields): void
    {
        $fieldsArray = explode(',', $fields);
        $fieldsHtml = '';
        $tableHeaders = '';
        $tableData = '';

        foreach ($fieldsArray as $field) {
            $fieldParts = explode(':', $field);
            $fieldName = trim($fieldParts[0]);
            $fieldsHtml .= "<div class=\"form-group\">\n";
            $fieldsHtml .= "    <label for=\"{$fieldName}\">".ucfirst($fieldName)."</label>\n";
            $fieldsHtml .= "    <input type=\"text\" class=\"form-control\" name=\"{$fieldName}\" id=\"{$fieldName}\">\n";
            $fieldsHtml .= "</div>\n";

            // Generate table header and data cell
            $tableHeaders .= '<th>'.ucfirst($fieldName)."</th>\n";
            $tableData .= "            <td>{{ \$item->{$fieldName} }}</td>\n";
        }

        // Define the directory for the Blade views
        $viewsDirectory = resource_path('views/'.Str::snake($name));
        if (! is_dir($viewsDirectory)) {
            mkdir($viewsDirectory, 0755, true);
        }

        // Create index.blade.php
        file_put_contents($viewsDirectory.'/index.blade.php',
            "<!-- resources/views/{$name}/index.blade.php -->\n".
            "@extends('layouts.app')\n".
            "@section('content')\n".
            '<h1>'.ucfirst($name)." List</h1>\n".
            '<a href="{{ route("'.Str::pluralStudly(Str::snake($name)).'.create") }}" class="btn btn-primary mb-3">Create New '.ucfirst($name).'</a>'."\n".
            "<table class=\"table\">\n".
            "    <thead>\n".
            "        <tr>\n".
            "            <th>#</th>\n".
            "{$tableHeaders}        \n".
            "            <th>Actions</th>\n".
            "        </tr>\n".
            "    </thead>\n".
            "    <tbody>\n".
            "        @foreach (\$items as \$item)\n".
            "        <tr>\n".
            "            <td>{{ \$item->id }}</td>\n".
            "{$tableData}            \n".
            "            <td>\n".
            "                <a href=\"{{ route('".Str::pluralStudly(Str::snake($name)).".show', \$item->id) }}\" class=\"btn btn-info\">Show</a>\n".
            "                <a href=\"{{ route('".Str::pluralStudly(Str::snake($name)).".edit', \$item->id) }}\" class=\"btn btn-warning\">Edit</a>\n".
            "                <form action=\"{{ route('".Str::pluralStudly(Str::snake($name)).".destroy', \$item->id) }}\" method=\"POST\" style=\"display:inline\">\n".
            "                    @csrf\n".
            "                    @method('DELETE')\n".
            "                    <button type=\"submit\" class=\"btn btn-danger\">Delete</button>\n".
            "                </form>\n".
            "            </td>\n".
            "        </tr>\n".
            "        @endforeach\n".
            "    </tbody>\n".
            "</table>\n".
            "@endsection\n"
        );

        // Create create.blade.php
        file_put_contents($viewsDirectory.'/create.blade.php',
            "<!-- resources/views/{$name}/create.blade.php -->\n".
            "@extends('layouts.app')\n".
            "@section('content')\n".
            '<h1>Create '.ucfirst($name)."</h1>\n".
            "<form action=\"{{ route('".Str::pluralStudly(Str::snake($name)).".store') }}\" method=\"POST\">\n".
            "    @csrf\n".
            $fieldsHtml.
            "    <button type=\"submit\" class=\"btn btn-primary\">Save</button>\n".
            "</form>\n".
            "@endsection\n"
        );

        // Create edit.blade.php
        file_put_contents($viewsDirectory.'/edit.blade.php',
            "<!-- resources/views/{$name}/edit.blade.php -->\n".
            "@extends('layouts.app')\n".
            "@section('content')\n".
            '<h1>Edit '.ucfirst($name)."</h1>\n".
            "<form action=\"{{ route('".Str::pluralStudly(Str::snake($name)).".update', \$item->id) }}\" method=\"POST\">\n".
            "    @csrf\n".
            "    @method('PUT')\n".
            $this->generateEditFormFields($fieldsArray).
            "    <button type=\"submit\" class=\"btn btn-success\">Update</button>\n".
            "</form>\n".
            "@endsection\n"
        );

        // Create show.blade.php
        file_put_contents($viewsDirectory.'/show.blade.php',
            "<!-- resources/views/{$name}/show.blade.php -->\n".
            "@extends('layouts.app')\n".
            "@section('content')\n".
            '<h1>'.ucfirst($name)." Details</h1>\n".
            "<div class=\"card\">\n".
            "    <div class=\"card-body\">\n".
            "        @foreach(\$item->getAttributes() as \$key => \$value)\n".
            "            @if(!in_array(\$key, ['id', 'created_at', 'updated_at']))\n".
            "                <div class=\"row mb-2\">\n".
            "                    <div class=\"col-sm-3 font-weight-bold\">\n".
            "                        {{ ucfirst(str_replace('_', ' ', \$key)) }}:\n".
            "                    </div>\n".
            "                    <div class=\"col-sm-9\">\n".
            "                        {{ \$value }}\n".
            "                    </div>\n".
            "                </div>\n".
            "            @endif\n".
            "        @endforeach\n".
            "        <a href=\"{{ route('".Str::pluralStudly(Str::snake($name)).".index') }}\" class=\"btn btn-primary mt-3\">Back to List</a>\n".
            "    </div>\n".
            "</div>\n".
            "@endsection\n"
        );

        // Define the directory for the Blade views
        $viewsDirectory = resource_path('views/layouts');
        if (! is_dir($viewsDirectory)) {
            mkdir($viewsDirectory, 0755, true);
        }

        // Create app.blade.php
        file_put_contents($viewsDirectory.'/app.blade.php',
            "<!-- resources/views/layouts/app.blade.php -->\n".
            "<!DOCTYPE html>\n".
            "<html lang=\"en\">\n".
            "<head>\n".
            "    <meta charset=\"UTF-8\">\n".
            "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n".
            "    <title>@yield('title', '".config('app.name')."')</title>\n".
            "</head>\n".
            "<body>\n".
            "    <div class=\"container mt-5\">\n".
            "        @yield('content')\n".
            "    </div>\n".
            "</body>\n".
            "</html>\n"
        );

        $this->info("Blade views for {$name} created successfully.");
    }

    protected function generateEditFormFields(array $fieldsArray): string
    {
        $fieldsHtml = '';

        foreach ($fieldsArray as $field) {
            $fieldParts = explode(':', $field);
            $fieldName = trim($fieldParts[0]);

            $fieldsHtml .= "<div class=\"form-group\">\n";
            $fieldsHtml .= "    <label for=\"{$fieldName}\">".ucfirst($fieldName)."</label>\n";
            $fieldsHtml .= "    <input type=\"text\" class=\"form-control\" name=\"{$fieldName}\" id=\"{$fieldName}\" value=\"{{ old('{$fieldName}', \$item->{$fieldName}) }}\">\n";
            $fieldsHtml .= "</div>\n";
        }

        return $fieldsHtml;
    }

    // Update the generated controller with dynamic methods
    protected function updateController($name, $fields): void
    {
        $controllerFile = app_path("Http/Controllers/{$name}Controller.php");
        $modelNamespace = "\App\\Models\\{$name}"; // Define the model namespace

        if (file_exists($controllerFile)) {
            $fieldsArray = explode(',', $fields);
            $fieldNames = array_map(fn ($field) => trim(explode(':', $field)[0]), $fieldsArray);

            // Read the existing controller content
            $controllerContent = file_get_contents($controllerFile);

            // Remove commented-out lines starting with //
            $controllerContent = preg_replace('/^\s*\/\/.*$/m', '', $controllerContent);

            // Remove any line breaks before the 'index' method
            $controllerContent = preg_replace('/\n\s*\n(?=\s*public function index)/', "\n", $controllerContent);

            $methods = [
                'index', 'create', 'store', 'show', 'edit', 'update', 'destroy',
            ];
            $existingMethods = [];

            // Check existing methods
            foreach ($methods as $method) {
                if (strpos($controllerContent, "public function {$method}(") !== false) {
                    $existingMethods[] = $method;
                }
            }

            // Create the new methods string
            $newMethods = '';
            if (! in_array('index', $existingMethods)) {
                $newMethods .= '    public function index()
    {
        $items = '.$modelNamespace.'::all();
        return view(\''.Str::snake($name).'.index\', compact(\'items\'));
    }'."\n";
            }

            if (! in_array('create', $existingMethods)) {
                $newMethods .= '
    public function create()
    {
        return view(\''.Str::snake($name).'.create\');
    }'."\n";
            }

            if (! in_array('store', $existingMethods)) {
                $newMethods .= '
    public function store(Request $request)
    {
        $validated = $request->validate([
            '.implode(",\n            ", array_map(fn ($field) => "'{$field}' => 'required'", $fieldNames)).'
        ]);

        '.$modelNamespace.'::create($validated);
        return redirect()->route(\''.Str::pluralStudly(Str::snake($name)).'.index\');
    }'."\n";
            }

            if (! in_array('show', $existingMethods)) {
                $newMethods .= '
    public function show($id)
    {
        $item = '.$modelNamespace.'::findOrFail($id);
        return view(\''.Str::snake($name).'.show\', compact(\'item\'));
    }'."\n";
            }

            if (! in_array('edit', $existingMethods)) {
                $newMethods .= '
    public function edit($id)
    {
        $item = '.$modelNamespace.'::findOrFail($id);
        return view(\''.Str::snake($name).'.edit\', compact(\'item\'));
    }'."\n";
            }

            if (! in_array('update', $existingMethods)) {
                $newMethods .= '
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            '.implode(",\n            ", array_map(fn ($field) => "'{$field}' => 'required'", $fieldNames)).'
        ]);

        $item = '.$modelNamespace.'::findOrFail($id);
        $item->update($validated);
        return redirect()->route(\''.Str::pluralStudly(Str::snake($name)).'.index\');
    }'."\n";
            }

            if (! in_array('destroy', $existingMethods)) {
                $newMethods .= '
    public function destroy($id)
    {
        $item = '.$modelNamespace.'::findOrFail($id);
        $item->delete();
        return redirect()->route(\''.Str::pluralStudly(Str::snake($name)).'.index\');
    }'."\n";
            }

            // If new methods are generated, append them just before the closing bracket of the class
            if ($newMethods) {
                // Find the position of the last closing bracket of the class
                $classEndPos = strrpos($controllerContent, '}');
                if ($classEndPos !== false) {
                    // Insert the new methods before the closing bracket
                    $controllerContent = substr_replace($controllerContent, $newMethods."\n", $classEndPos, 0);
                    file_put_contents($controllerFile, $controllerContent);
                    $this->info("CRUD methods for {$name} added to the controller.");
                }
            } else {
                $this->warn("CRUD methods for {$name} already exist in the controller.");
            }
        }
    }

    // Add resource route to web.php
    protected function addResourceRoute($name): void
    {
        $routeFile = base_path('routes/web.php');
        $controllerClass = "\App\Http\Controllers\\{$name}Controller";

        // Define the resource route string
        $resourceRoute = "Route::resource('".Str::pluralStudly(Str::snake($name))."', {$controllerClass}::class);";

        // Check if the route already exists
        if (file_exists($routeFile)) {
            $routesContent = file_get_contents($routeFile);

            // Add the resource route if it doesn't already exist
            if (strpos($routesContent, $resourceRoute) === false) {
                // Append the new resource route to the file
                file_put_contents($routeFile, "\n".$resourceRoute."\n", FILE_APPEND);
                $this->info("Resource route for {$name} added to routes/web.php.");
            } else {
                $this->warn("Resource route for {$name} already exists in routes/web.php.");
            }
        }
    }
}
