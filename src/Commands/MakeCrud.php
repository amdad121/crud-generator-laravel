<?php

declare(strict_types=1);

namespace AmdadulHaq\CRUDGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    protected $signature = 'make:crud {name?} {--fields=} {--api}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a model, migration, controller, and Blade views with specified fields for CRUD operations';

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

    public function handle(): void
    {
        $name = $this->argument('name');

        if (! $name) {
            $name = text(
                label: 'Enter the model name',
                validate: ['name' => 'required|max:255|unique:users']
            );
        }

        $fields = $this->option('fields');

        if (empty($fields)) {
            $fields = $this->askForFields();
        }

        if (config('crud_generator.generate_migration', true)) {
            $migrationFields = $this->generateMigrationFields($fields);
        }

        if (config('crud_generator.generate_model', true)) {
            $this->call('make:model', [
                'name' => $name,
                '--migration' => true,
            ]);

            $this->addFillableToModel($name, $fields);

            if (config('crud_generator.soft_deletes', false)) {
                $this->addSoftDeletesToModel($name);
            }
        }

        if (config('crud_generator.generate_migration', true)) {
            $this->updateMigrationFile($name, $migrationFields);
        }

        if (config('crud_generator.generate_controller', true)) {
            $this->call('make:controller', [
                'name' => $name.'Controller',
                '--resource' => false,
            ]);

            $this->updateController($name, $fields);
        }

        if (config('crud_generator.generate_blade', true)) {
            $this->createBladeViews($name, $fields);
        }

        if (config('crud_generator.generate_routes', true)) {
            $this->addResourceRoute($name);
        }

        if ($this->option('api') || config('crud_generator.api_controller', false)) {
            $this->createApiController($name, $fields);
            $this->addApiResourceRoute($name);
        }

        $this->info(sprintf('CRUD operations for %s created successfully. now you can view %s', $name, url(Str::pluralStudly(Str::snake($name)))));
    }

    protected function askForFields(): string
    {
        $fields = [];
        while (true) {
            $fieldName = text('Enter a field name or leave blank to finish');
            if ($fieldName === '' || $fieldName === '0') {
                break;
            }

            // Prompt to select the field type from the options
            $fieldType = select('Select field type', $this->validColumnTypes);

            // Validate and add the field
            if ($this->validateField($fieldName, $fieldType)) {
                $fields[] = sprintf('%s:%s', $fieldName, $fieldType);
            }
        }

        return implode(',', $fields);
    }

    protected function validateField(string $fieldName, string $fieldType): bool
    {
        $fieldType = trim($fieldType);

        if (! in_array($fieldType, $this->validColumnTypes)) {
            $this->error(sprintf("Invalid field type '%s'. Valid types are: %s", $fieldType, implode(', ', $this->validColumnTypes)));

            return false;
        }

        return true;
    }

    protected function generateMigrationFields(string $fields): string
    {
        $migrationFields = '';

        if ($fields !== '' && $fields !== '0') {
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

    protected function updateMigrationFile(string $name, string $migrationFields): void
    {
        $className = Str::pluralStudly($name);
        $timestamp = now()->format('Y_m_d_His');
        $migrationFile = database_path(sprintf('migrations/%s_create_%s_table.php', $timestamp, Str::snake($className)));

        if (file_exists($migrationFile)) {
            $migrationContent = file_get_contents($migrationFile);

            $softDeletesColumn = '';
            if (config('crud_generator.soft_deletes', false)) {
                $softDeletesColumn = "\$table->softDeletes();\n            ";
            }

            $migrationContent = str_replace(
                '$table->timestamps();',
                rtrim($migrationFields)."\n            ".$softDeletesColumn.'$table->timestamps();',
                $migrationContent
            );
            file_put_contents($migrationFile, $migrationContent);
        }

        if ($this->laravel->environment('testing')) {
            return;
        }

        $tableName = Str::snake($className);
        if (! Schema::hasTable($tableName)) {
            $this->runMigration($migrationFile);
        } else {
            $this->info(sprintf("Table '%s' already exists. Migration will not run.", $tableName));
        }
    }

    protected function runMigration(string $migrationFile): void
    {
        $this->info('Running migration...');

        Artisan::call('migrate', [
            '--path' => 'database/migrations/'.basename($migrationFile),
            '--quiet' => true,
        ]);

        $this->info('Migration ran successfully.');
    }

    protected function addFillableToModel(string $name, string $fields): void
    {
        $modelFile = app_path(sprintf('Models/%s.php', $name));

        if (file_exists($modelFile)) {
            $fillableFields = implode("', '", array_map(fn ($field): string => trim(explode(':', $field)[0]), explode(',', $fields)));

            $modelContent = file_get_contents($modelFile);

            if (! preg_match('/protected\s+\$fillable\s*=\s*\[/', $modelContent)) {
                $modelContent = preg_replace(
                    '/class\s+'.preg_quote($name, '/').'\s*extends\s+Model\s*{/',
                    'class '.$name.' extends Model {'."\n\n    protected \$fillable = ['{$fillableFields}'];\n",
                    $modelContent
                );
                file_put_contents($modelFile, $modelContent);
            }
        }
    }

    protected function addSoftDeletesToModel(string $name): void
    {
        $modelFile = app_path(sprintf('Models/%s.php', $name));

        if (file_exists($modelFile)) {
            $modelContent = file_get_contents($modelFile);

            if (! str_contains($modelContent, 'use SoftDeletes;') && ! str_contains($modelContent, SoftDeletes::class)) {
                $modelContent = preg_replace(
                    '/namespace App\\\\Models;/',
                    "namespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;",
                    $modelContent
                );

                $modelContent = preg_replace(
                    '/class\s+'.preg_quote($name, '/').'\s+extends\s+Model\s*{/',
                    'class '.$name.' extends Model'."\n".'{'."\n    use SoftDeletes;\n",
                    (string) $modelContent
                );

                file_put_contents($modelFile, $modelContent);
            }
        }
    }

    protected function createBladeViews(string $name, string $fields): void
    {
        $fieldsArray = $this->parseFields($fields);
        $viewsDirectory = resource_path('views/'.Str::snake($name));

        if (! is_dir($viewsDirectory)) {
            mkdir($viewsDirectory, 0755, true);
        }

        $stubPath = __DIR__.'/../../stubs/';
        $pluralRouteName = Str::pluralStudly(Str::snake($name));

        $replacements = [
            '{{ name }}' => $name,
            '{{ Name }}' => ucfirst($name),
            '{{ plural_route_name }}' => $pluralRouteName,
        ];

        $this->generateViewFromStub($stubPath.'index.blade.stub', $viewsDirectory.'/index.blade.php', $replacements, $fieldsArray);
        $this->generateViewFromStub($stubPath.'create.blade.stub', $viewsDirectory.'/create.blade.php', $replacements, $fieldsArray);
        $this->generateViewFromStub($stubPath.'edit.blade.stub', $viewsDirectory.'/edit.blade.php', $replacements, $fieldsArray);
        $this->generateViewFromStub($stubPath.'show.blade.stub', $viewsDirectory.'/show.blade.php', $replacements, $fieldsArray);

        $layoutsDirectory = resource_path('views/layouts');
        if (! is_dir($layoutsDirectory)) {
            mkdir($layoutsDirectory, 0755, true);
        }

        if (! file_exists($layoutsDirectory.'/app.blade.php')) {
            copy($stubPath.'app.blade.stub', $layoutsDirectory.'/app.blade.php');
        }

        $this->info(sprintf('Blade views for %s created successfully.', $name));
    }

    protected function generateViewFromStub(string $stubPath, string $targetPath, array $replacements, array $fields): void
    {
        if (! file_exists($stubPath)) {
            $this->error('Stub file not found: '.$stubPath);

            return;
        }

        $content = file_get_contents($stubPath);

        foreach ($replacements as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }

        $content = $this->replaceFieldPlaceholders($content, $fields);

        file_put_contents($targetPath, $content);
    }

    protected function replaceFieldPlaceholders(string $content, array $fields): string
    {
        $tableHeaders = '';
        $tableData = '';
        $formFields = '';

        foreach ($fields as $field) {
            $fieldName = $field['name'];
            $tableHeaders .= "                <th>{{ ucfirst('{$fieldName}') }}</th>\n";
            $tableData .= "                <td>{{ \$item->{$fieldName} }}</td>\n";

            $formFields .= "        <div class=\"form-group\">\n";
            $formFields .= "            <label for=\"{$fieldName}\">{{ ucfirst('{$fieldName}') }}</label>\n";
            $formFields .= sprintf('            <input type="text" class="form-control" name="%s" id="%s"', $fieldName, $fieldName);

            if (str_contains($content, 'old(')) {
                $formFields .= sprintf(" value=\"{{ old('%s', \$item->%s ?? '') }}\"", $fieldName, $fieldName);
            }

            $formFields .= ">\n";
            $formFields .= "        </div>\n";
        }

        $content = str_replace('{{ table_headers }}', trim($tableHeaders), $content);
        $content = str_replace('{{ table_data }}', trim($tableData), $content);

        return str_replace('{{ form_fields }}', trim($formFields), $content);
    }

    protected function parseFields(string $fields): array
    {
        $parsed = [];
        $fieldArray = explode(',', $fields);

        foreach ($fieldArray as $field) {
            $parts = explode(':', $field);
            $parsed[] = [
                'name' => trim($parts[0]),
                'type' => trim($parts[1] ?? 'string'),
            ];
        }

        return $parsed;
    }

    protected function generateEditFormFields(array $fieldsArray): string
    {
        $fieldsHtml = '';

        foreach ($fieldsArray as $field) {
            $fieldParts = explode(':', (string) $field);
            $fieldName = trim($fieldParts[0]);

            $fieldsHtml .= "<div class=\"form-group\">\n";
            $fieldsHtml .= sprintf('    <label for="%s">%s</label>\n', $fieldName, ucfirst($fieldName));
            $fieldsHtml .= "    <input type=\"text\" class=\"form-control\" name=\"{$fieldName}\" id=\"{$fieldName}\" value=\"{{ old('{$fieldName}', \$item->{$fieldName}) }}\">\n";
            $fieldsHtml .= "</div>\n";
        }

        return $fieldsHtml;
    }

    protected function updateController(string $name, string $fields): void
    {
        $controllerFile = app_path(sprintf('Http/Controllers/%sController.php', $name));
        $modelNamespace = '\App\Models\\'.$name; // Define the model namespace

        if (file_exists($controllerFile)) {
            $fieldsArray = $this->parseFields($fields);
            $fieldNames = array_map(fn (array $field) => $field['name'], $fieldsArray);

            // Read the existing controller content
            $controllerContent = file_get_contents($controllerFile);

            // Remove commented-out lines starting with //
            $controllerContent = preg_replace('/^\s*\/\/.*$/m', '', $controllerContent);

            // Remove any line breaks before the 'index' method
            $controllerContent = preg_replace('/\n\s*\n(?=\s*public function index)/', "\n", (string) $controllerContent);

            $methods = [
                'index', 'create', 'store', 'show', 'edit', 'update', 'destroy',
            ];
            $existingMethods = [];

            // Check existing methods
            foreach ($methods as $method) {
                if (str_contains((string) $controllerContent, sprintf('public function %s(', $method))) {
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
                $validationRules = $this->getValidationRules($fieldsArray);
                $newMethods .= '
    public function store(Request $request)
    {
        $validated = $request->validate([
            '.implode(",\n            ", array_map(fn (string $field): string => sprintf("'%s' => '%s'", $field, $validationRules[$field]), $fieldNames)).'
        ]);

        '.$modelNamespace.'::create($validated);
        return redirect()->route(\''.Str::pluralStudly(Str::snake($name)).'.index\');
    }'."\n";
            }

            if (! in_array('update', $existingMethods)) {
                $validationRules = $this->getValidationRules($fieldsArray);
                $newMethods .= '
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            '.implode(",\n            ", array_map(fn (string $field): string => sprintf("'%s' => '%s'", $field, $validationRules[$field]), $fieldNames)).'
        ]);

        $item = '.$modelNamespace.'::findOrFail($id);
        $item->update($validated);
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
            '.implode(",\n            ", array_map(fn (string $field): string => sprintf("'%s' => 'required'", $field), $fieldNames)).'
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
            if ($newMethods !== '' && $newMethods !== '0') {
                // Find the position of the last closing bracket of the class
                $classEndPos = strrpos((string) $controllerContent, '}');
                if ($classEndPos !== false) {
                    // Insert the new methods before the closing bracket
                    $controllerContent = substr_replace($controllerContent, $newMethods."\n", $classEndPos, 0);
                    file_put_contents($controllerFile, $controllerContent);
                    $this->info(sprintf('CRUD methods for %s added to the controller.', $name));
                }
            } else {
                $this->warn(sprintf('CRUD methods for %s already exist in the controller.', $name));
            }
        }
    }

    protected function getValidationRules(array $fields): array
    {
        $rules = [];
        $configRules = config('crud_generator.validation_rules', []);
        $defaultRule = $configRules['default'] ?? 'required';

        foreach ($fields as $field) {
            $fieldType = $field['type'];
            $fieldName = $field['name'];

            if (isset($configRules[$fieldType])) {
                $rules[$fieldName] = $configRules[$fieldType];
            } elseif (str_contains((string) $fieldName, 'email')) {
                $rules[$fieldName] = $configRules['email'] ?? 'required|email';
            } else {
                $rules[$fieldName] = $defaultRule;
            }
        }

        return $rules;
    }

    protected function addResourceRoute(string $name): void
    {
        $routeFile = base_path('routes/web.php');
        $controllerClass = sprintf('\App\Http\Controllers\%sController', $name);

        // Define the resource route string
        $resourceRoute = sprintf("Route::resource('%s', %s::class);", Str::pluralStudly(Str::snake($name)), $controllerClass);

        // Check if the route already exists
        if (file_exists($routeFile)) {
            $routesContent = file_get_contents($routeFile);

            // Add the resource route if it doesn't already exist
            if (! str_contains($routesContent, $resourceRoute)) {
                // Append the new resource route to the file
                file_put_contents($routeFile, "\n".$resourceRoute."\n", FILE_APPEND);
                $this->info(sprintf('Resource route for %s added to routes/web.php.', $name));
            } else {
                $this->warn(sprintf('Resource route for %s already exists in routes/web.php.', $name));
            }
        }
    }

    protected function createApiController(string $name, string $fields): void
    {
        $this->call('make:controller', [
            'name' => sprintf('Api/%sController', $name),
            '--api' => true,
        ]);

        $controllerFile = app_path(sprintf('Http/Controllers/Api/%sController.php', $name));
        $modelNamespace = '\App\Models\\'.$name;
        $fieldsArray = $this->parseFields($fields);
        $fieldNames = array_map(fn (array $field) => $field['name'], $fieldsArray);
        $validationRules = $this->getValidationRules($fieldsArray);

        if (file_exists($controllerFile)) {
            $controllerContent = file_get_contents($controllerFile);
            $validationArray = implode(",\n            ", array_map(fn (string $field): string => sprintf("'%s' => '%s'", $field, $validationRules[$field]), $fieldNames));

            $storeMethod = "
    public function store(Request \$request)
    {
        \$validated = \$request->validate([
            {$validationArray}
        ]);

        \$item = {$modelNamespace}::create(\$validated);
        return response()->json(\$item, 201);
    }
";
            $updateMethod = "
    public function update(Request \$request, \$id)
    {
        \$validated = \$request->validate([
            {$validationArray}
        ]);

        \$item = {$modelNamespace}::findOrFail(\$id);
        \$item->update(\$validated);
        return response()->json(\$item);
    }
";

            if (! str_contains($controllerContent, 'public function store(')) {
                $controllerContent = str_replace(
                    '}',
                    $storeMethod."\n}",
                    $controllerContent
                );
            }

            if (! str_contains($controllerContent, 'public function update(')) {
                $controllerContent = str_replace(
                    '}',
                    $updateMethod."\n}",
                    $controllerContent
                );
            }

            file_put_contents($controllerFile, $controllerContent);
            $this->info(sprintf('API controller for %s created successfully.', $name));
        }
    }

    protected function addApiResourceRoute(string $name): void
    {
        $routeFile = base_path('routes/api.php');
        $controllerClass = sprintf('\App\Http\Controllers\Api\%sController', $name);
        $resourceRoute = sprintf("Route::apiResource('%s', %s::class);", Str::pluralStudly(Str::snake($name)), $controllerClass);

        if (file_exists($routeFile)) {
            $routesContent = file_get_contents($routeFile);

            if (! str_contains($routesContent, $resourceRoute)) {
                file_put_contents($routeFile, "\n".$resourceRoute."\n", FILE_APPEND);
                $this->info(sprintf('API resource route for %s added to routes/api.php.', $name));
            } else {
                $this->warn(sprintf('API resource route for %s already exists in routes/api.php.', $name));
            }
        }
    }
}
