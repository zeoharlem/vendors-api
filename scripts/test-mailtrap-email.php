<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use App\Modules\Notification\Domain\Contracts\EmailDeliveryRepositoryInterface;
use App\Modules\Notification\Application\Messages\SendEmailMessage;
use Dotenv\Dotenv;
use Symfony\Component\Messenger\MessageBusInterface;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

if (class_exists(Dotenv::class)) {
    Dotenv::createImmutable($root)->safeLoad();
}

try {
    $application = ApplicationFactory::create($root);
    $di = $application->getDI();

    /** @var EmailDeliveryRepositoryInterface $deliveryRepository */
    $deliveryRepository = $di->getShared(
        EmailDeliveryRepositoryInterface::class
    );

    /** @var MessageBusInterface $messageBus */
    $messageBus = $di->getShared(
        MessageBusInterface::class
    );

    $testReference = bin2hex(random_bytes(12));

    $delivery = $deliveryRepository->createPending(
        idempotencyKey: 'manual-mailtrap-test:' . $testReference,
        outboxEventId: null,
        recipientEmail: 'sandbox-recipient@example.com',
        recipientName: 'Sandbox Test Customer',
        templateKey: 'order_confirmation',
        subject: 'Vendors API - Mailtrap Sandbox Test',
        templateData: [
            'customer_name' => 'Sandbox Test Customer',
            'order_number' => 'TEST-' . strtoupper($testReference),
            'currency' => 'NGN',
            'order_total_minor' => 17000000,
            'items' => [
                [
                    'name' => 'Test Marketplace Product',
                    'quantity' => 2,
                    'line_total_minor' => 17000000,
                ],
            ],
        ],
    );

    $deliveryId = (string) $delivery['id'];

    $messageBus->dispatch(
        new SendEmailMessage($deliveryId)
    );

    fwrite(
        STDOUT,
        sprintf(
            "[%s] Mailtrap test email queued successfully.\nDelivery ID: %s\n",
            date('Y-m-d H:i:s'),
            $deliveryId
        )
    );

    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        sprintf(
            "[%s] Mailtrap test dispatch failed: %s\n",
            date('Y-m-d H:i:s'),
            $exception->getMessage()
        )
    );

    exit(1);
}
