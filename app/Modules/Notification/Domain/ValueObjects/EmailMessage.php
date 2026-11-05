<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\ValueObjects;

final class EmailMessage
{
    public function __construct(
        public readonly string $recipientEmail,
        public readonly ?string $recipientName,
        public readonly string $subject,
        public readonly string $htmlContent,
        public readonly ?string $textContent = null,
    ) {
    }
}