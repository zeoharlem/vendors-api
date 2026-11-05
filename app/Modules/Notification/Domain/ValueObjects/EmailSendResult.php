<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\ValueObjects;

final class EmailSendResult
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $providerMessageId,
    ) {
    }
}