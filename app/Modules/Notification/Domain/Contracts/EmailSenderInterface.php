<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Contracts;

use App\Modules\Notification\Domain\ValueObjects\EmailMessage;
use App\Modules\Notification\Domain\ValueObjects\EmailSendResult;

interface EmailSenderInterface
{
    public function send(EmailMessage $message): EmailSendResult;
}