<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\ValueObjects;

final class EmailTemplateContent
{
    public function __construct(
        public readonly string $htmlContent,
        public readonly ?string $textContent = null,
    ) {
    }
}