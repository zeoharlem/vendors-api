<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

use App\Modules\Notification\Domain\ValueObjects\EmailTemplateContent;

interface EmailTemplateRendererInterface
{
    public function render(
        string $templateKey,
        array $templateData,
    ): EmailTemplateContent;
}