<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use App\Modules\Payout\Application\Services\PaystackTransferWebhookService;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

try {
    $app = ApplicationFactory::create($root);
    $di = $app->getDI();

    /** @var PaystackTransferWebhookService $service */
    $service = $di->getShared(
        PaystackTransferWebhookService::class
    );

    $payload = [
        'event' => 'transfer.success',
        'data' => [
            'id' => 77043866,
            'reference' => 'tr_7fbde678d844bbc3ad491c1d583d8301388826e6',
            'transfer_code' => 'TRF_MOCK_497989AAEE6CB8C3',
            'amount' => 4050000,
            'currency' => 'NGN',
            'status' => 'success',
            'reason' => 'Vendor payout b8e959bc-a2ae-4b7b-bab1-9e0633935296',
        ],
    ];

    $result = $service->handle($payload);

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Webhook simulation failed: ' . $exception->getMessage() . PHP_EOL
    );

    exit(1);
}
