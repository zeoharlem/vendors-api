<?php

declare(strict_types=1);

use App\Bootstrap\ApplicationFactory;
use App\Modules\Notification\Application\Messages\TransportSmokeTestMessage;
use Doctrine\DBAL\Connection as DoctrineDbalConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

try {
    $application = ApplicationFactory::create($root);
    $di = $application->getDI();

    /** @var DoctrineDbalConnection $connection */
    $connection = $di->getShared(DoctrineDbalConnection::class);

    /** @var DoctrineTransport $transport */
    $transport = $di->getShared(DoctrineTransport::class);

    // 1. Verify the database connection.
    $connection->executeQuery('SELECT 1')->fetchOne();

    echo "Doctrine DBAL connection successful.\n";

    // 2. Create a unique test message.
    $testId = bin2hex(random_bytes(8));

    $message = new TransportSmokeTestMessage(
        testId: $testId,
        message: 'Messenger PostgreSQL transport test',
    );

    // 3. Publish the message.
    $sentEnvelope = $transport->send(
        new Envelope($message)
    );

    echo "Message published successfully.\n";
    echo 'Test ID: ' . $testId . PHP_EOL;

    // 4. Retrieve the message from the queue.
    $receivedEnvelope = null;

    foreach ($transport->get() as $envelope) {
        $receivedEnvelope = $envelope;
        break;
    }

    if ($receivedEnvelope === null) {
        throw new RuntimeException(
            'Message was published but could not be retrieved.'
        );
    }

    // 5. Verify deserialization.
    $receivedMessage = $receivedEnvelope->getMessage();

    if (!$receivedMessage instanceof TransportSmokeTestMessage) {
        throw new RuntimeException(
            'Retrieved message has an unexpected type: '
            . get_debug_type($receivedMessage)
        );
    }

    if ($receivedMessage->testId !== $testId) {
        throw new RuntimeException('Retrieved message ID does not match.');
    }

    echo "Message retrieved and deserialized successfully.\n";

    // 6. Acknowledge the message.
    $transport->ack($receivedEnvelope);

    echo "Message acknowledged successfully.\n";

    echo "Messenger transport publish/retrieve test passed.\n";

    $connection->close();

    exit(0);

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "Messenger transport test failed: "
        . $exception->getMessage()
        . PHP_EOL
        . $exception->getTraceAsString()
        . PHP_EOL
    );

    exit(1);
}