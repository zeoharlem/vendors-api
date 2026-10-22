<?php

declare(strict_types=1);

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$env = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return ($value === false || $value === null)
        ? $default
        : (string) $value;
};

return [
    'database' => [
        'adapter' => 'Postgresql',
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => $env('DB_PORT', '5432'),
        'username' => $env('DB_USERNAME'),
        'password' => $env('DB_PASSWORD'),
        'dbname' => $env('DB_NAME'),
    ],

    'application' => [
        'logInDb' => true,
        'migrationsDir' => 'database/migrations',
        'migrationsTsBased' => true,
        'exportDataFromTables' => [],
    ],
];
