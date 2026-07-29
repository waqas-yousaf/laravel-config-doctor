<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Scan Directories
    |--------------------------------------------------------------------------
    |
    | Define the directories in your project that should be recursively scanned
    | for environment variable references (using env() or custom helpers).
    |
    */
    'scan_dirs' => [
        'app',
        'bootstrap',
        'config',
        'database',
        'resources',
        'routes',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Extensions
    |--------------------------------------------------------------------------
    |
    | Only files with these extensions will be scanned for environment variables.
    |
    */
    'extensions' => [
        'php',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Unused Keys
    |--------------------------------------------------------------------------
    |
    | Environment variable keys that should not trigger warnings when they
    | are defined in .env files but not referenced in your PHP code.
    | By default, keys starting with 'APP_' are ignored.
    |
    */
    'ignore_unused' => [
        'APP_KEY',
        'APP_ENV',
        'APP_DEBUG',
        'APP_URL',
        'APP_NAME',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scan Helpers / Classes
    |--------------------------------------------------------------------------
    |
    | You can add custom environment retrieval functions or classes here.
    | For example, if you use Env::get('KEY') or similar functions.
    |
    */
    'helpers' => [
        'env',
        'Env::get',
    ],
];
