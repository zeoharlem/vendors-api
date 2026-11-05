<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Email;

use App\Modules\Notification\Domain\Contracts\EmailSenderInterface;
use App\Modules\Notification\Domain\ValueObjects\EmailMessage;
use App\Modules\Notification\Domain\ValueObjects\EmailSendResult;
use Mailtrap\Helper\ResponseHelper;
use Mailtrap\MailtrapClient;
use Mailtrap\Mime\MailtrapEmail;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Mailtrap\Api\EmailsSendApiInterface;

final class MailtrapEmailSender implements EmailSenderInterface
{
    private readonly EmailsSendApiInterface $client;

    public function __construct(
        string $apiKey,
        int $inboxId,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
        if (trim($apiKey) === '') {
            throw new RuntimeException('Mailtrap API key is not configured.');
        }

        if ($inboxId < 1) {
            throw new RuntimeException('A valid Mailtrap Sandbox inbox ID is required.');
        }

        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Mailtrap sender email address is invalid.');
        }

        $this->client = MailtrapClient::initSendingEmails(
            apiKey: $apiKey,
            isSandbox: true,
            inboxId: $inboxId,
        );
    }

    public function send(EmailMessage $message): EmailSendResult
    {
        if (
            filter_var(
                $message->recipientEmail,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new RuntimeException('Recipient email address is invalid.');
        }

        $email = (new MailtrapEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to(new Address(
                $message->recipientEmail,
                $message->recipientName ?? ''
            ))
            ->subject($message->subject);

        if ($message->textContent !== null) {
            $email->text($message->textContent);
        }

        if ($message->htmlContent !== '') {
            $email->html($message->htmlContent);
        }

        $response = $this->client->send($email);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(
                sprintf('Mailtrap rejected the email. HTTP status: %d', $statusCode)
            );
        }

        $responseData = ResponseHelper::toArray($response);

        $messageIds = $responseData['message_ids'] ?? [];

        $providerMessageId = is_array($messageIds)
            ? ($messageIds[0] ?? null)
            : null;

        return new EmailSendResult(
            statusCode: $statusCode,
            providerMessageId: is_string($providerMessageId)
            ? $providerMessageId
            : null,
        );
    }
}
