<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use App\Modules\Auth\Infrastructure\Models\User;

header('Content-Type: text/plain');

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

// Load environment variables here if your public/index.php
// normally loads them before creating the application.
if (class_exists(\Dotenv\Dotenv::class)) {
    $dotenv = \Dotenv\Dotenv::createImmutable($root);
    $dotenv->safeLoad();
}

try {
    $application = ApplicationFactory::create($root);
    $di = $application->getDI();

    // echo "Application DI initialized successfully." . PHP_EOL;

    // $user = new User();
    // $user->setDI($di);

    // echo "User model instantiated successfully." . PHP_EOL;
    // echo "Phalcon version: " . phpversion('phalcon') . PHP_EOL;
    $db = $di->getShared('db');

    echo "STEP 2: Database service created." . PHP_EOL;

    $result = $db->query('SELECT 1 AS test_value');
    $result->execute();

    echo "STEP 3: Direct database query succeeded." . PHP_EOL;

    $row = $result->fetch();

    echo "Query result: " . json_encode($row) . PHP_EOL;
} catch (\Throwable $exception) {
    echo 'Exception: ' . get_class($exception) . PHP_EOL;
    echo 'Message: ' . $exception->getMessage() . PHP_EOL;
}
