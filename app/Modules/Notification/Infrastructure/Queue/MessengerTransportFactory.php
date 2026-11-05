<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Queue;

use Doctrine\DBAL\Connection as DoctrineDbalConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineMessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class MessengerTransportFactory
{
    private const TRANSPORT_DSN = 'doctrine://default';

    private const DEFAULT_OPTIONS = [
        'table_name' => 'messenger_messages',
        'queue_name' => 'emails',
        'redeliver_timeout' => 3600,
        'auto_setup' => false,
    ];

    public static function create(
        DoctrineDbalConnection $dbalConnection,
        string $queueName = 'emails',
    ): DoctrineTransport {
        if (!in_array($queueName, ['emails', 'failed_emails'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported Messenger queue: %s', $queueName)
            );
        }

        $options = array_merge(
            self::DEFAULT_OPTIONS,
            ['queue_name' => $queueName]
        );

        $configuration = DoctrineMessengerConnection::buildConfiguration(
            self::TRANSPORT_DSN,
            $options
        );

        // Use the base Doctrine connection implementation.
        // This gives us polling rather than PostgreSQL LISTEN/NOTIFY.
        $messengerConnection = new DoctrineMessengerConnection(
            $configuration,
            $dbalConnection
        );

        return new DoctrineTransport(
            $messengerConnection,
            new PhpSerializer()
        );
    }
}