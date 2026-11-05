<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use App\Modules\Notification\Infrastructure\Messenger\EmailRetryStrategy;
use App\Modules\Notification\Infrastructure\Messenger\MessengerServiceContainer;
use App\Modules\Notification\Infrastructure\Messenger\StopWorkerAfterMessageLimitListener;
use App\Modules\Notification\Infrastructure\Queue\FailedEmailTransport;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$lockName = 'vendors-api-email-worker';

$lockAcquired = false;

$exitCode = 0;

try {
    $application = ApplicationFactory::create($root);

    $di = $application->getDI();

    /** @var Connection $connection */
    $connection = $di->getShared(Connection::class);

    /*
     * Prevent overlapping cPanel cron executions.
     */
    $lockResult = $connection->executeQuery(
        <<<'SQL'
            SELECT pg_try_advisory_lock(hashtext(:lock_name))
        SQL,
        [
            'lock_name' => $lockName,
        ]
    )->fetchOne();

    $lockAcquired = in_array(
        $lockResult,
        [true, 1, '1', 't', 'true'],
        true
    );

    if (!$lockAcquired) {
        fwrite(
            STDOUT,
            sprintf(
                "[%s] Email worker already running. Skipping.\n",
                date('Y-m-d H:i:s')
            )
        );
    } else {
        // Put the rest of the worker setup and run code here.
    }

    /** @var DoctrineTransport $emailsTransport */
    $emailsTransport = $di->getShared(DoctrineTransport::class);

    /** @var DoctrineTransport $failedTransport */
    $failedTransport = $di->getShared(FailedEmailTransport::class);

    /** @var MessageBusInterface $bus */
    $bus = $di->getShared(MessageBusInterface::class);

    /*
     * The retry listener resolves the original sender by receiver name.
     */
    $senders = new MessengerServiceContainer([
        'emails' => $emailsTransport,
    ]);

    $retryStrategies = new MessengerServiceContainer([
        'emails' => new EmailRetryStrategy(),
    ]);

    /*
     * Failed messages from "emails" are forwarded to "failed_emails".
     */
    $failureSenders = new MessengerServiceContainer([
        'emails' => $failedTransport,
    ]);

    $eventDispatcher = new EventDispatcher();

    $eventDispatcher->addSubscriber(
        new SendFailedMessageForRetryListener(
            $senders,
            $retryStrategies
        )
    );

    $eventDispatcher->addSubscriber(
        new SendFailedMessageToFailureTransportListener(
            $failureSenders
        )
    );

    $messageLimit = 50;
    $timeLimit = 45;

    $limitListener = new StopWorkerAfterMessageLimitListener(
        messageLimit: $messageLimit
    );

    $worker = new Worker(
        [
            'emails' => $emailsTransport,
        ],
        $bus,
        $eventDispatcher
    );

    $limitListener->setWorker($worker);

    $eventDispatcher->addSubscriber($limitListener);

    fwrite(
        STDOUT,
        sprintf(
            "[%s] Email worker started. Message limit: %d. Time limit: %d seconds.\n",
            date('Y-m-d H:i:s'),
            $messageLimit,
            $timeLimit
        )
    );

    $worker->run([
        'sleep' => 250_000,
        'time_limit' => $timeLimit,
    ]);

    fwrite(
        STDOUT,
        sprintf(
            "[%s] Email worker stopped. Messages processed: %d.\n",
            date('Y-m-d H:i:s'),
            $limitListener->getProcessedMessages()
        )
    );

    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        sprintf(
            "[%s] Email worker failed: %s\n%s\n",
            date('Y-m-d H:i:s'),
            $exception->getMessage(),
            $exception->getTraceAsString()
        )
    );

    $exitCode = 1;
} finally {
    if ($lockAcquired && isset($connection)) {
        try {
            $connection->executeQuery(
                <<<'SQL'
                    SELECT pg_advisory_unlock(hashtext(:lock_name))
                SQL,
                [
                    'lock_name' => $lockName,
                ]
            );
        } catch (Throwable $exception) {
            fwrite(
                STDERR,
                sprintf(
                    "[%s] Could not release email worker lock: %s\n",
                    date('Y-m-d H:i:s'),
                    $exception->getMessage()
                )
            );
        }
    }
}

exit($exitCode);

/** Once the local worker test succeeds, configure the cPanel Cron Job: **/
// * * * * * /usr/local/bin/php /home/CPANEL_USERNAME/vendors-api/cli/consume-email-queue.php >> /home/CPANEL_USERNAME/logs/email-worker.log 2>&1