<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Messages;

final class SendEmailMessage
{
    public function __construct(
        public readonly string $deliveryId,
    ) {
        if (trim($deliveryId) === '') {
            throw new \InvalidArgumentException(
                'Email delivery ID cannot be empty.'
            );
        }
    }
}