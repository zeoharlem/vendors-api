<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Messenger;

use App\Modules\Notification\Application\Messages\SendEmailMessage;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

final class EmailMessageSenderLocator implements SendersLocatorInterface
{
    public function __construct(
        private readonly DoctrineTransport $transport,
    ) {
    }

    public function getSenders(Envelope $envelope): iterable
    {
        if ($envelope->getMessage() instanceof SendEmailMessage) {
            yield $this->transport;
        }
    }
}