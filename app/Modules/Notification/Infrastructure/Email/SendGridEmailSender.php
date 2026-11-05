<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Email;

use App\Modules\Notification\Domain\Contracts\EmailSenderInterface;
use App\Modules\Notification\Domain\ValueObjects\EmailMessage;
use App\Modules\Notification\Domain\ValueObjects\EmailSendResult;
use SendGrid;
use SendGrid\Mail\Mail;
use RuntimeException;

final class SendGridEmailSender implements EmailSenderInterface
{
    private readonly SendGrid $client;
    private readonly string $fromEmail;
    private readonly string $fromName;

    public function __construct(
        string $apiKey,
        string $fromEmail,
        string $fromName,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('SendGrid API key is not configured.');
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid sender email configuration.');
        }

        $this->client = new SendGrid($apiKey);
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        if (!filter_var($message->recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid recipient email address.');
        }

        $mail = new Mail();

        $mail->setFrom($this->fromEmail, $this->fromName);
        $mail->setSubject($message->subject);

        $mail->addTo(
            $message->recipientEmail,
            $message->recipientName ?? ''
        );

        if ($message->textContent !== null && $message->textContent !== '') {
            $mail->addContent('text/plain', $message->textContent);
        }

        $mail->addContent('text/html', $message->htmlContent);

        $response = $this->client->send($mail);

        $statusCode = $response->statusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(
                sprintf('SendGrid rejected the email. HTTP status: %d', $statusCode)
            );
        }

        $headers = $response->headers();
        $providerMessageId = null;

        foreach ($headers as $header) {
            if (stripos($header, 'X-Message-Id:') === 0) {
                $providerMessageId = trim(
                    substr($header, strlen('X-Message-Id:'))
                );

                break;
            }
        }

        return new EmailSendResult(
            statusCode: $statusCode,
            providerMessageId: $providerMessageId,
        );
    }
}