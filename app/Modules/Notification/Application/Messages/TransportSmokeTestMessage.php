<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Messages;

final class TransportSmokeTestMessage
{
    public function __construct(
        public readonly string $testId,
        public readonly string $message,
    ) {
    }
}