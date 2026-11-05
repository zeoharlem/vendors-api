<?php

declare(strict_types=1);

namespace App\Modules\Notification\Infrastructure\Email;

use App\Modules\Notification\Domain\Contracts\EmailTemplateRendererInterface;
use App\Modules\Notification\Domain\ValueObjects\EmailTemplateContent;
use InvalidArgumentException;

final class PhpEmailTemplateRenderer implements EmailTemplateRendererInterface
{
    public function render(
        string $templateKey,
        array $templateData,
    ): EmailTemplateContent {
        return match ($templateKey) {
            'order_confirmation' => $this->renderOrderConfirmation($templateData),
            'payment_success' => $this->renderPaymentSuccess($templateData),
            'payment_failed' => $this->renderPaymentFailed($templateData),

            default => throw new InvalidArgumentException(
                sprintf('Unsupported email template: %s', $templateKey)
            ),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderOrderConfirmation(array $data,): EmailTemplateContent {
        $customerName = $this->optionalString(
            $data,
            'customer_name',
            'there'
        );

        $orderNumber = $this->requiredString($data, 'order_number');
        $currency = strtoupper($this->requiredString($data, 'currency'));

        $orderTotalMinor = $this->requiredMinorAmount(
            $data,
            'order_total_minor'
        );

        $items = $data['items'] ?? [];

        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException(
                'Order confirmation items must be a list.'
            );
        }

        $htmlItems = [];
        $textItems = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(
                    'Each order item must be an object.'
                );
            }

            $name = $this->requiredString($item, 'name');
            $quantity = $this->requiredPositiveInteger($item, 'quantity');

            $lineTotalMinor = $this->requiredMinorAmount(
                $item,
                'line_total_minor'
            );

            $formattedLineTotal = $this->formatMinorAmount(
                $currency,
                $lineTotalMinor
            );

            $htmlItems[] = sprintf(
                '<tr>
                    <td style="padding:12px 8px;border-bottom:1px solid #eee;">%s</td>
                    <td style="padding:12px 8px;text-align:center;border-bottom:1px solid #eee;">%d</td>
                    <td style="padding:12px 8px;text-align:right;border-bottom:1px solid #eee;">%s</td>
                </tr>',
                $this->escape($name),
                $quantity,
                $this->escape($formattedLineTotal)
            );

            $textItems[] = sprintf(
                '%s x %d - %s',
                $name,
                $quantity,
                $formattedLineTotal
            );
        }

        if ($items === []) {
            $htmlItems[] = '
                <tr>
                    <td colspan="3" style="padding:12px 8px;">
                        No item details available.
                    </td>
                </tr>
            ';

            $textItems[] = 'No item details available.';
        }

        $formattedTotal = $this->formatMinorAmount(
            $currency,
            $orderTotalMinor
        );

        $orderUrl = $this->optionalHttpsUrl($data, 'view_order_url');

        $actionHtml = '';

        $actionText = '';

        if ($orderUrl !== null) {
            $actionHtml = sprintf(
                '<p style="margin:28px 0;">
                    <a href="%s"
                       style="display:inline-block;background:#172554;color:#ffffff;
                       text-decoration:none;padding:13px 22px;border-radius:6px;">
                        View your order
                    </a>
                </p>',
                $this->escape($orderUrl)
            );

            $actionText = "\nView your order: {$orderUrl}\n";
        }

        $html = $this->layout(
            sprintf(
                <<<'HTML'
                <h2 style="color:#172554;margin-top:0;">Order confirmed!</h2>

                <p>Hello %s,</p>

                <p>
                    Thank you for shopping with us.
                    Your order has been received successfully.
                </p>

                <p>
                    <strong>Order number:</strong> %s
                </p>

                <h3 style="margin-top:28px;">Order summary</h3>

                <table style="width:100%%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th style="padding:12px 8px;text-align:left;border-bottom:2px solid #ddd;">
                                Item
                            </th>
                            <th style="padding:12px 8px;text-align:center;border-bottom:2px solid #ddd;">
                                Qty
                            </th>
                            <th style="padding:12px 8px;text-align:right;border-bottom:2px solid #ddd;">
                                Total
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        %s
                    </tbody>
                </table>

                <p style="text-align:right;font-size:18px;margin-top:20px;">
                    <strong>Order total: %s</strong>
                </p>

                %s

                <p>
                    We will keep you updated as your order progresses.
                </p>
                HTML,
                $this->escape($customerName),
                $this->escape($orderNumber),
                implode("\n", $htmlItems),
                $this->escape($formattedTotal),
                $actionHtml
            )
        );

        $text = implode("\n", [
            'ORDER CONFIRMED',
            '',
            sprintf('Hello %s,', $customerName),
            '',
            'Thank you for shopping with us. Your order has been received successfully.',
            '',
            sprintf('Order number: %s', $orderNumber),
            '',
            'ORDER SUMMARY',
            implode("\n", $textItems),
            '',
            sprintf('Order total: %s', $formattedTotal),
            $actionText,
            'We will keep you updated as your order progresses.',
        ]);

        return new EmailTemplateContent(
            htmlContent: $html,
            textContent: $text
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPaymentSuccess(array $data,): EmailTemplateContent {
        $customerName = $this->optionalString(
            $data,
            'customer_name',
            'there',
        );

        $orderNumber = $this->requiredString($data, 'order_number');
        $reference = $this->requiredString($data, 'reference');
        $currency = strtoupper($this->requiredString($data, 'currency'));

        $amountMinor = $this->requiredMinorAmount(
            $data,
            'amount_minor',
        );

        $formattedAmount = $this->formatMinorAmount(
            $currency,
            $amountMinor,
        );

        $html = $this->layout(
            sprintf(
                <<<'HTML'
            <h2 style="color:#166534;margin-top:0;">Payment successful!</h2>

            <p>Hello %s,</p>

            <p>
                We have successfully received your payment.
                Your order is now paid and is being processed.
            </p>

            <p>
                <strong>Order number:</strong> %s
            </p>

            <p>
                <strong>Payment reference:</strong> %s
            </p>

            <p style="font-size:18px;margin-top:24px;">
                <strong>Amount paid: %s</strong>
            </p>

            <p>
                Thank you for shopping with us.
                We will keep you updated as your order progresses.
            </p>
            HTML,
                $this->escape($customerName),
                $this->escape($orderNumber),
                $this->escape($reference),
                $this->escape($formattedAmount),
            ),
        );

        $text = implode("\n", [
            'PAYMENT SUCCESSFUL',
            '',
            sprintf('Hello %s,', $customerName),
            '',
            'We have successfully received your payment.',
            'Your order is now paid and is being processed.',
            '',
            sprintf('Order number: %s', $orderNumber),
            sprintf('Payment reference: %s', $reference),
            sprintf('Amount paid: %s', $formattedAmount),
            '',
            'Thank you for shopping with us.',
            'We will keep you updated as your order progresses.',
        ]);

        return new EmailTemplateContent(
            htmlContent: $html,
            textContent: $text,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPaymentFailed(array $data,): EmailTemplateContent {
        $customerName = $this->optionalString(
            $data,
            'customer_name',
            'there',
        );

        $orderNumber = $this->requiredString($data, 'order_number');
        $reference = $this->requiredString($data, 'reference');
        $currency = strtoupper($this->requiredString($data, 'currency'));

        $amountMinor = $this->requiredMinorAmount(
            $data,
            'amount_minor',
        );

        $failureReason = $this->optionalString(
            $data,
            'failure_reason',
            'The payment provider reported that the transaction was unsuccessful.',
        );

        $formattedAmount = $this->formatMinorAmount(
            $currency,
            $amountMinor,
        );

        $html = $this->layout(
            sprintf(
                <<<'HTML'
            <h2 style="color:#b91c1c;margin-top:0;">Payment unsuccessful</h2>

            <p>Hello %s,</p>

            <p>
                Unfortunately, we could not confirm your payment.
                Your order has not been marked as paid.
            </p>

            <p>
                <strong>Order number:</strong> %s
            </p>

            <p>
                <strong>Payment reference:</strong> %s
            </p>

            <p>
                <strong>Amount:</strong> %s
            </p>

            <p>
                <strong>Reason:</strong> %s
            </p>

            <p>
                You can return to your account and retry the payment
                if your order is still awaiting payment.
            </p>

            <p>
                If your account was debited despite this notification,
                please contact our support team with your payment reference.
            </p>
            HTML,
                $this->escape($customerName),
                $this->escape($orderNumber),
                $this->escape($reference),
                $this->escape($formattedAmount),
                $this->escape($failureReason),
            ),
        );

        $text = implode("\n", [
            'PAYMENT UNSUCCESSFUL',
            '',
            sprintf('Hello %s,', $customerName),
            '',
            'Unfortunately, we could not confirm your payment.',
            'Your order has not been marked as paid.',
            '',
            sprintf('Order number: %s', $orderNumber),
            sprintf('Payment reference: %s', $reference),
            sprintf('Amount: %s', $formattedAmount),
            sprintf('Reason: %s', $failureReason),
            '',
            'You can return to your account and retry the payment',
            'if your order is still awaiting payment.',
            '',
            'If your account was debited despite this notification,',
            'please contact our support team with your payment reference.',
        ]);

        return new EmailTemplateContent(
            htmlContent: $html,
            textContent: $text,
        );
    }

    private function layout(string $content): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Marketplace Notification</title>
        </head>
        <body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#333;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                    <td align="center">
                        <table role="presentation"
                               width="100%"
                               cellspacing="0"
                               cellpadding="0"
                               style="max-width:620px;background:#ffffff;border-radius:8px;">
                            <tr>
                                <td style="padding:32px;">
                                    {$content}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:20px 32px;background:#f8fafc;color:#777;font-size:12px;text-align:center;">
                                    This is an automated notification from your marketplace.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                sprintf('Email template field "%s" is required.', $key)
            );
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalString(
        array $data,
        string $key,
        string $default,
    ): string {
        $value = $data[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Email template field "%s" must be a string.', $key)
            );
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredMinorAmount(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'Email template field "%s" must be a non-negative integer in minor units.',
                    $key
                )
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredPositiveInteger(
        array $data,
        string $key,
    ): int {
        $value = $data[$key] ?? null;

        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Email template field "%s" must be a positive integer.',
                    $key
                )
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optionalHttpsUrl(
        array $data,
        string $key,
    ): ?string {
        $value = $data[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('Email template field "%s" must be a URL.', $key)
            );
        }

        $parts = parse_url($value);

        if (
            filter_var($value, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
        ) {
            throw new InvalidArgumentException(
                sprintf('Email template field "%s" must be a valid HTTPS URL.', $key)
            );
        }

        return $value;
    }

    private function formatMinorAmount(
        string $currency,
        int $amountMinor,
    ): string {
        $major = intdiv($amountMinor, 100);
        $minor = $amountMinor % 100;

        return sprintf(
            '%s %s.%02d',
            $currency,
            number_format($major),
            $minor
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}