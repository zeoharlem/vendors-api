<?php

declare(strict_types=1);

use Phalcon\Config\Config;

return new Config([
    'database' => [
        'adapter' => 'Postgresql',
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => (int) ($_ENV['DB_PORT'] ?? 5432),
        'username' => $_ENV['DB_USERNAME'] ?? 'postgres',
        'password' => $_ENV['DB_PASSWORD'] ?? 'admin2020',
        'dbname' => $_ENV['DB_NAME'] ?? 'multivendors',
    ],
    'application' => [
        'migrationsDir' => 'database/migrations',
        'migrationsTsBased' => true,
        'logInDb' => true,
    ],
]);
