<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Handlers;

use App\Modules\Notification\Application\Messages\SendEmailMessage;
use App\Modules\Notification\Domain\Contracts\EmailDeliveryRepositoryInterface;
use App\Shared\Enums\EventTypes;
use InvalidArgumentException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class VendorOrderEmailHandler
{
    public function __construct(
        private AdapterInterface $db,
        private EmailDeliveryRepositoryInterface $deliveryRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Creates and queues the customer notification for a vendor-order event.
     *
     * @param array<string, mixed> $eventPayload
     */
    public function handle(
        string $eventId,
        string $vendorOrderId,
        EventTypes $eventType,
        array $eventPayload = [],
    ): void {
        $notification = match ($eventType) {
            EventTypes::VENDOR_ORDER_ACCEPTED => [
                'status' => 'accepted',
                'template' => 'vendor_order_accepted',
                'subject' => 'Your order has been accepted',
            ],

            EventTypes::VENDOR_ORDER_SHIPPED => [
                'status' => 'shipped',
                'template' => 'vendor_order_shipped',
                'subject' => 'Your order has been shipped',
            ],

            EventTypes::VENDOR_ORDER_COMPLETED => [
                'status' => 'completed',
                'template' => 'vendor_order_completed',
                'subject' => 'Your order is completed',
            ],

            default => throw new InvalidArgumentException(
                sprintf(
                    'Unsupported vendor order email event: %s',
                    $eventType->value,
                )
            ),
        };

        if ($eventId === ''|| $vendorOrderId === '') {
            throw new InvalidArgumentException(
                'Outbox event ID and vendor order ID are required.'
            );
        }

        if (isset($eventPayload['vendor_order_id']) && $eventPayload['vendor_order_id'] !== $vendorOrderId) {
            throw new RuntimeException(
                'Vendor order email payload does not match the requested vendor order.'
            );
        }

        $eventStatus = $eventPayload['status'] ?? null;

        if (
            !is_string($eventStatus)
            || $eventStatus !== $notification['status']
        ) {
            throw new RuntimeException(
                'Vendor order event contains an invalid status.'
            );
        }

        $vendorOrder = $this->findVendorOrderForEmail($vendorOrderId);

        if ($vendorOrder === null) {
            throw new RuntimeException(
                sprintf(
                    'Vendor order "%s" could not be found for email notification.',
                    $vendorOrderId,
                )
            );
        }

        $recipientEmail = trim((string) ($vendorOrder['customer_email'] ?? ''));

        if (
            $recipientEmail === ''
            || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new RuntimeException(
                sprintf(
                    'Vendor order "%s" has no valid customer email address.',
                    $vendorOrderId,
                )
            );
        }

        $firstName = trim((string) ($vendorOrder['first_name'] ?? ''));
        $lastName = trim((string) ($vendorOrder['last_name'] ?? ''));

        $customerName = trim($firstName . ' ' . $lastName);

        if ($customerName === '') {
            $customerName = 'there';
        }

        $orderNumber = trim((string) ($vendorOrder['order_number'] ?? ''));

        if ($orderNumber === '') {
            throw new RuntimeException(
                sprintf(
                    'Vendor order "%s" has no associated order number.',
                    $vendorOrderId,
                )
            );
        }

        /*
         * The outbox payload is the authoritative source for values
         * associated with the transition. This matters when the order
         * has advanced to another status before this event is processed.
         */
        $trackingNumber = array_key_exists('tracking_number', $eventPayload)
            ? $eventPayload['tracking_number']
            : ($vendorOrder['tracking_number'] ?? null);

        $trackingUrl = array_key_exists('tracking_url', $eventPayload)
            ? $eventPayload['tracking_url']
            : ($vendorOrder['tracking_url'] ?? null);

        if (
            $trackingNumber !== null
            && !is_string($trackingNumber)
        ) {
            throw new RuntimeException(
                'Vendor order event contains an invalid tracking number.'
            );
        }

        if (
            $trackingUrl !== null
            && !is_string($trackingUrl)
        ) {
            throw new RuntimeException(
                'Vendor order event contains an invalid tracking URL.'
            );
        }

        $templateData = [
            'customer_name' => $customerName,
            'order_number' => $orderNumber,
            'vendor_order_id' => $vendorOrderId,
            'status' => $eventStatus,
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl,
        ];

        if ($eventType === EventTypes::VENDOR_ORDER_COMPLETED) {
            $completionSource = $eventPayload['completion_source'] ?? null;

            if (
                !is_string($completionSource)
                || !in_array(
                    $completionSource,
                    [
                        'customer_confirmation',
                        'automatic_acceptance',
                    ],
                    true,
                )
            ) {
                throw new RuntimeException(
                    'Completed vendor order event has an invalid completion source.'
                );
            }

            $templateData['completion_source'] = $completionSource;
        }

        $delivery = $this->deliveryRepository->createPending(
            idempotencyKey: 'vendor_order.email:' . $eventId,
            outboxEventId: $eventId,
            recipientEmail: $recipientEmail,
            recipientName: $customerName,
            templateKey: $notification['template'],
            subject: sprintf(
                '%s - %s',
                $notification['subject'],
                $orderNumber,
            ),
            templateData: $templateData,
        );

        $deliveryId = $delivery['id'] ?? null;

        if (!is_string($deliveryId) || $deliveryId === '') {
            throw new RuntimeException(
                'Email delivery repository did not return a valid delivery ID.'
            );
        }

        $this->messageBus->dispatch(
            new SendEmailMessage($deliveryId)
        );
    }

    /**
     * Retrieves the customer and order information needed by the email.
     *
     * This deliberately does not filter by the vendor order's current status:
     * an older outbox event must still be deliverable after a later transition.
     *
     * @return array<string, mixed>|null
     */
    private function findVendorOrderForEmail(
        string $vendorOrderId,
    ): ?array {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                vo.id AS vendor_order_id,
                vo.order_id,
                vo.store_id,
                vo.status,
                vo.tracking_number,
                vo.tracking_url,
                vo.delivered_at,
                vo.completed_at,
                o.order_number,
                u.email AS customer_email,
                u.first_name,
                u.last_name
            FROM public.vendor_orders vo
            INNER JOIN public.orders o
                ON o.id = vo.order_id
            INNER JOIN public.users u
                ON u.id = o.customer_id
            WHERE vo.id = :vendor_order_id
            LIMIT 1
            SQL,
            [
                'vendor_order_id' => $vendorOrderId,
            ],
        );

        $row = $result->fetch();

        return is_array($row) ? $row : null;
    }
}