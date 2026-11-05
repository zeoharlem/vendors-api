<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Handlers;

use App\Modules\Notification\Application\Messages\SendEmailMessage;
use App\Modules\Notification\Domain\Contracts\EmailDeliveryRepositoryInterface;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class OrderPlacedEmailHandler
{
    public function __construct(
        private AdapterInterface $db,
        private EmailDeliveryRepositoryInterface $deliveryRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Creates and queues an order-confirmation email.
     *
     * @throws Throwable
     */
    public function handle(string $eventId, string $orderId): void
    {
        if (trim($eventId) === '' || trim($orderId) === '') {
            throw new RuntimeException(
                'Outbox event ID and order ID are required.'
            );
        }

        // Load the order and its customer.
        $order = $this->db->query(
            <<<'SQL'
            SELECT
                o.id,
                o.order_number,
                o.currency,
                o.total_minor,
                u.id AS customer_id,
                u.email,
                u.first_name,
                u.last_name
            FROM public.orders o
            INNER JOIN public.users u ON u.id = o.customer_id
            WHERE o.id = :order_id
            LIMIT 1
            SQL,
            [
                'order_id' => $orderId,
            ],
        )->fetch();

        if (!$order) {
            throw new RuntimeException(
                sprintf('Order "%s" could not be found.', $orderId)
            );
        }

        $recipientEmail = trim((string) $order['email']);

        if ($recipientEmail === '') {
            throw new RuntimeException(
                sprintf('Customer email is missing for order "%s".', $orderId)
            );
        }

        $customerName = trim(
            (string) $order['first_name'] . ' ' . (string) $order['last_name']
        );

        // Load the order items for the confirmation template.
        $orderItems = $this->db->query(
            <<<'SQL'
            SELECT
                product_name,
                variant_name,
                quantity,
                line_total_minor
            FROM public.order_items
            WHERE order_id = :order_id
            ORDER BY created_at ASC, id ASC
            SQL,
            [
                'order_id' => $orderId,
            ],
        )->fetchAll();

        if (!$orderItems) {
            throw new RuntimeException(
                sprintf('No order items were found for order "%s".', $orderId)
            );
        }

        $items = [];

        foreach ($orderItems as $item) {
            $productName = trim((string) $item['product_name']);
            $variantName = trim((string) ($item['variant_name'] ?? ''));

            $itemName = $variantName !== ''
                ? $productName . ' - ' . $variantName
                : $productName;

            $items[] = [
                'name' => $itemName,
                'quantity' => (int) $item['quantity'],
                'line_total_minor' => (int) $item['line_total_minor'],
            ];
        }

        $orderNumber = (string) $order['order_number'];

        // The event ID makes email creation idempotent.
        $delivery = $this->deliveryRepository->createPending(
            idempotencyKey: 'order_confirmation:' . $eventId,
            outboxEventId: $eventId,
            recipientEmail: $recipientEmail,
            recipientName: $customerName !== '' ? $customerName : null,
            templateKey: 'order_confirmation',
            subject: 'Your order ' . $orderNumber . ' has been placed',
            templateData: [
                'customer_name' => $customerName,
                'order_number' => $orderNumber,
                'currency' => (string) $order['currency'],
                'order_total_minor' => (int) $order['total_minor'],
                'items' => $items,
            ],
        );

        $deliveryId = $delivery['id'] ?? null;

        if (!is_string($deliveryId) || trim($deliveryId) === '') {
            throw new RuntimeException(
                'The email delivery repository did not return a valid delivery ID.'
            );
        }

        // Queue the email through the existing Messenger transport.
        $this->messageBus->dispatch(
            new SendEmailMessage($deliveryId)
        );
    }
}