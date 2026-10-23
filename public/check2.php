<?php

declare(strict_types=1);

use Phalcon\Db\Adapter\Pdo\Postgresql;

header('Content-Type: text/plain');

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

putenv('PGGSSENCMODE=disable');
//putenv('PGGSSLIB=none');

try {
    echo "STEP 1: Creating Phalcon PostgreSQL adapter...\n";

    $db = new Postgresql([
        'host' => '127.0.0.1',
        'port' => 5432,
        'dbname' => 'multivendors',
        'username' => 'postgres',
        'password' => 'admin2020',
    ]);

    echo "STEP 2: Adapter created.\n";

    $result = $db->query('SELECT 1 AS test_value');

    echo "STEP 3: Query executed.\n";
    var_dump($result->fetch());
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
