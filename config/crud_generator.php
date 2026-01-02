<?php

declare(strict_types=1);

return [
    'generate_migration' => true,
    'generate_model' => true,
    'generate_controller' => true,
    'generate_routes' => true,
    'generate_blade' => true,
    'soft_deletes' => false,
    'api_controller' => false,
    'validation_rules' => [
        'default' => 'required',
        'string' => 'required|string|max:255',
        'text' => 'required',
        'integer' => 'required|integer',
        'boolean' => 'boolean',
        'date' => 'required|date',
        'dateTime' => 'required|date',
        'timestamp' => 'required|date',
        'email' => 'required|email',
    ],
];
