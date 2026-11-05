<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use App\Modules\Notification\Infrastructure\Outbox\OutboxEventDispatcher;
use Dotenv\Dotenv;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$logDirectory = $root . '/storage/logs';

if (!is_dir($logDirectory)) {
    if (!mkdir($logDirectory, 0750, true) && !is_dir($logDirectory)) {
        throw new RuntimeException(
            sprintf('Could not create log directory: %s', $logDirectory),
        );
    }
}

$logFile = $logDirectory . '/outbox-dispatcher.log';
$lockFile = $root . '/storage/outbox-dispatcher.lock';

function writeLog(string $message): void
{
    global $logFile;

    $line = sprintf(
        "[%s] %s%s",
        date('Y-m-d H:i:s'),
        $message,
        PHP_EOL,
    );

    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only run from the command line.' . PHP_EOL);
}

$lockHandle = null;
$lockAcquired = false;

try {
    $lockHandle = fopen($lockFile, 'c');

    if ($lockHandle === false) {
        throw new RuntimeException('Could not open the outbox dispatcher lock file.');
    }

    $lockAcquired = flock($lockHandle, LOCK_EX | LOCK_NB);

    $lockAcquired = flock($lockHandle, LOCK_EX | LOCK_NB);

    if (!$lockAcquired) {
        writeLog('Another outbox dispatcher instance is already running.');
        fclose($lockHandle);
        $lockHandle = null;
    } else {
        $app = ApplicationFactory::create($root);
        $di = $app->getDI();

        /** @var OutboxEventDispatcher $dispatcher */
        $dispatcher = $di->getShared(OutboxEventDispatcher::class);

        $result = $dispatcher->dispatchPending(limit: 25);

        writeLog(
            sprintf(
                'Outbox dispatch completed. Claimed: %d, processed: %d, retry scheduled: %d, permanently failed: %d.',
                $result['claimed'],
                $result['processed'],
                $result['retry_scheduled'],
                $result['permanently_failed'],
            )
        );
    }
} catch (Throwable $exception) {
    writeLog(sprintf(
        'Outbox dispatcher error [%s]: %s',
            $exception::class,
        $exception->getMessage(),
    ));

    //writeLog($exception->getTraceAsString());

    exit(1);
} finally {
    if (is_resource($lockHandle)) {
        if ($lockAcquired) {
            flock($lockHandle, LOCK_UN);
        }

        fclose($lockHandle);
    }
}
