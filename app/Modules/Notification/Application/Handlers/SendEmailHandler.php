<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Handlers;

use App\Modules\Notification\Application\Messages\SendEmailMessage;
use App\Modules\Notification\Domain\Contracts\EmailDeliveryRepositoryInterface;
use App\Modules\Notification\Domain\Contracts\EmailSenderInterface;
use App\Modules\Notification\Domain\Contracts\EmailTemplateRendererInterface;
use App\Modules\Notification\Domain\ValueObjects\EmailMessage;
use RuntimeException;
use Throwable;

final class SendEmailHandler
{
    public function __construct(
        private readonly EmailDeliveryRepositoryInterface $deliveryRepository,
        private readonly EmailSenderInterface $emailSender,
        private readonly EmailTemplateRendererInterface $templateRenderer,
    ) {
    }

    public function __invoke(SendEmailMessage $message): void
    {
        $delivery = $this->deliveryRepository->findById(
            $message->deliveryId
        );

        if ($delivery === null) {
            throw new RuntimeException(
                'Email delivery record was not found: '
                . $message->deliveryId
            );
        }

        // A previously completed delivery must never be sent again.
        if ($delivery['status'] === 'sent') {
            return;
        }

        // Only one worker can claim a delivery at a time.
        if (
            !$this->deliveryRepository->claimForProcessing(
                $message->deliveryId
            )
        ) {
            // Another worker may have completed or claimed it.
            // Do not send concurrently.
            return;
        }

        try {
            $templateKey = $delivery['template_key'] ?? null;

            if (!is_string($templateKey) || trim($templateKey) === '') {
                throw new RuntimeException(
                    'Email delivery does not have a valid template key.'
                );
            }

            $templateData = $delivery['template_data'] ?? [];

            if (is_string($templateData)) {
                $templateData = json_decode(
                    $templateData,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }

            if (!is_array($templateData)) {
                throw new RuntimeException(
                    'Email template data must be a valid JSON object.'
                );
            }

            $content = $this->templateRenderer->render(
                $templateKey,
                $templateData
            );

            $result = $this->emailSender->send(
                new EmailMessage(
                    recipientEmail: (string) $delivery['recipient_email'],
                    recipientName: $delivery['recipient_name'] ?? null,
                    subject: (string) $delivery['subject'],
                    htmlContent: $content->htmlContent,
                    textContent: $content->textContent,
                )
            );
        } catch (Throwable $exception) {
            try {
                $this->deliveryRepository->markFailed(
                    $message->deliveryId,
                    $exception->getMessage()
                );
            } catch (Throwable $trackingException) {
                throw new RuntimeException(
                    'Email delivery failed and its failure status could not be recorded.',
                    0,
                    $exception
                );
            }

            // Messenger must receive the exception so its retry mechanism runs.
            throw $exception;
        }

        // SendGrid has accepted the email. If recording this fails,
        // propagate the error so it can be investigated.
        if (
            !$this->deliveryRepository->markSent(
                $message->deliveryId,
                $result->providerMessageId
            )
        ) {
            throw new RuntimeException(
                'SendGrid accepted the email, but the delivery status could not be updated. '
                . 'Delivery ID: ' . $message->deliveryId
            );
        }
    }
}