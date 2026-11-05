<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use Symfony\Component\Messenger\MessageBusInterface;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

try {
    $application = ApplicationFactory::create($root);

    $di = $application->getDI();

    $bus = $di->getShared(MessageBusInterface::class);

    if (!$bus instanceof MessageBusInterface) {
        throw new RuntimeException(
            'Messenger bus was not resolved correctly.'
        );
    }

    $handler = $di->getShared(
        \App\Modules\Notification\Application\Handlers\SendEmailHandler::class
    );

    $locator = $di->getShared(
        \App\Modules\Notification\Infrastructure\Messenger\EmailMessageSenderLocator::class
    );

    echo 'Messenger bus resolved successfully.' . PHP_EOL;
    echo 'Email handler resolved successfully.' . PHP_EOL;
    echo 'Email sender locator resolved successfully.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        sprintf(
            "Messenger bus test failed: %s\n%s\n",
            $exception->getMessage(),
            $exception->getTraceAsString()
        )
    );

    exit(1);
}