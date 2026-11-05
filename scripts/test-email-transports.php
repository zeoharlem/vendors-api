<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use App\Modules\Notification\Infrastructure\Queue\FailedEmailTransport;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

try {
    $application = ApplicationFactory::create($root);

    $di = $application->getDI();

    /** @var Connection $connection */
    $connection = $di->getShared(Connection::class);

    $connection->executeQuery('SELECT 1')->fetchOne();

    /** @var DoctrineTransport $emailsTransport */
    $emailsTransport = $di->getShared(DoctrineTransport::class);

    /** @var DoctrineTransport $failedTransport */
    $failedTransport = $di->getShared(FailedEmailTransport::class);

    if (!$emailsTransport instanceof DoctrineTransport) {
        throw new RuntimeException('Email transport resolution failed.');
    }

    if (!$failedTransport instanceof DoctrineTransport) {
        throw new RuntimeException('Failed email transport resolution failed.');
    }

    if ($emailsTransport === $failedTransport) {
        throw new RuntimeException(
            'Email and failed transports must be separate instances.'
        );
    }

    echo "Doctrine DBAL connection successful.\n";
    echo "Email transport resolved successfully.\n";
    echo "Failed email transport resolved successfully.\n";
    echo "Both transports use separate instances.\n";
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        sprintf(
            "Email transport test failed: %s\n%s\n",
            $exception->getMessage(),
            $exception->getTraceAsString()
        )
    );

    exit(1);
}