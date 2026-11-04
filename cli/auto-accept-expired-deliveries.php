<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use App\Modules\Vendor\Application\Jobs\AutoAcceptExpiredDeliveriesJob;
use Dotenv\Dotenv;

$root = dirname(__DIR__);

// Load Composer dependencies.
require_once $root . '/vendor/autoload.php';

// Load environment variables when phpdotenv is installed.
// safeLoad() allows the script to continue if .env is absent.
if (class_exists(Dotenv::class)) {
    Dotenv::createImmutable($root)->safeLoad();
}

try {
    // Build the application and its existing DI container.
    $application = ApplicationFactory::create($root);
    $di = $application->getDI();

    /** @var AutoAcceptExpiredDeliveriesJob $job */
    $job = $di->getShared(AutoAcceptExpiredDeliveriesJob::class);

    $batchSize = 100;

    $completedCount = $job->run($batchSize);

    $message = sprintf(
        "[%s] Automatic delivery acceptance completed. Processed: %d\n",
        date('Y-m-d H:i:s'),
        $completedCount
    );

    fwrite(STDOUT, $message);

    exit(0);
} catch (Throwable $exception) {
    $message = sprintf(
        "[%s] Automatic delivery acceptance failed: %s\n%s\n",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getTraceAsString()
    );

    fwrite(STDERR, $message);

    exit(1);
}

/** CPANEL configs */
// */5 * * * * /path/to/cpanel/php /home/CPANEL_USERNAME/vendors-api/cli/auto-accept-expired-deliveries.php >> /home/CPANEL_USERNAME/logs/auto-accept-deliveries.log 2>&1