<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use Doctrine\DBAL\Connection as DoctrineDbalConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

$root = __DIR__;

require_once $root . '/../vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

try {
    $application = ApplicationFactory::create($root);
    $di = $application->getDI();

    /** @var DoctrineDbalConnection $connection */
    $connection = $di->getShared(DoctrineDbalConnection::class);

    if (!$connection instanceof Doctrine\DBAL\Connection) {
        throw new RuntimeException(
            'Doctrine DBAL connection service was not resolved correctly. '
            . 'Received: ' . get_debug_type($connection)
        );
    }

    $connection->executeQuery('SELECT 1')->fetchOne();

    echo "Doctrine DBAL connection successful.\n";

    /** @var DoctrineTransport $transport */
    $transport = $di->getShared(DoctrineTransport::class);

    echo 'Messenger transport initialized: '
        . get_class($transport)
        . PHP_EOL;

    echo "Messenger transport test successful.\n";

    $connection->close();

    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "Messenger transport test failed: "
        . $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}
