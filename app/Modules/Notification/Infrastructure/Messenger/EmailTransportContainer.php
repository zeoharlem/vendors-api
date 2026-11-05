<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Messenger;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;


final class EmailTransportContainer implements ContainerInterface
{
    public function __construct(
        private readonly DoctrineTransport $emailsTransport,
        private readonly DoctrineTransport $failedEmailsTransport,
    ) {
    }

    public function get(string $id): DoctrineTransport
    {
        return match ($id) {
            'emails' => $this->emailsTransport,
            'failed_emails' => $this->failedEmailsTransport,

            default => throw new \InvalidArgumentException(
                sprintf('Unknown email transport: %s', $id)
            ),
        };
    }

    public function has(string $id): bool
    {
        return in_array($id, ['emails', 'failed_emails'], true);
    }
}