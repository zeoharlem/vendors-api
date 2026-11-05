<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class DoctrineConnectionFactory
{
    public static function create(array $config): Connection
    {

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => (string) $config['host'],
            'port' => (int) $config['port'],
            'dbname' => (string) $config['dbname'],
            'user' => (string) $config['username'],
            'password' => (string) $config['password'],
        ]);
    }
}
