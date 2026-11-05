<?php
declare(strict_types=1);

namespace App\Shared\Enums;

enum EventTypes: string
{
    // A. Order lifecycle
    case ORDER_PLACED = 'order.placed';
    case ORDER_CANCELLED = 'order.cancelled';
    case ORDER_COMPLETED = 'order.completed';

    // B. Payment lifecycle
    case PAYMENT_SUCCEEDED = 'payment.succeeded';
    case PAYMENT_FAILED = 'payment.failed';
    case PAYMENT_REFUNDED = 'payment.refunded';

    // C. Vendor order lifecycle
    case VENDOR_ORDER_ACCEPTED = 'vendor_order.accepted';
    case VENDOR_ORDER_REJECTED = 'vendor_order.rejected';
    case VENDOR_ORDER_SHIPPED = 'vendor_order.shipped';
    case VENDOR_ORDER_COMPLETED = 'vendor_order.completed';

    // D. Dispute lifecycle
    case DISPUTE_OPENED = 'dispute.opened';
    case DISPUTE_RESOLVED = 'dispute.resolved';

    /**
     * Optional helper method to get a human-readable description or purpose.
     */
    public function description(): string
    {
        return match($this) {
            self::ORDER_PLACED => 'Customer order confirmation',
            self::ORDER_CANCELLED => 'Notify customer and affected vendors',
            self::ORDER_COMPLETED => 'Notify customer that the order is completed',
            self::PAYMENT_SUCCEEDED => 'Confirm successful payment and notify customer',
            self::PAYMENT_FAILED => 'Notify customer that payment failed',
            self::PAYMENT_REFUNDED => 'Notify customer of a successful refund',
            self::VENDOR_ORDER_ACCEPTED => 'Notify customer that a vendor accepted their order',
            self::VENDOR_ORDER_REJECTED => 'Notify customer that a vendor rejected their order',
            self::VENDOR_ORDER_SHIPPED => 'Notify customer that their order has been shipped',
            self::VENDOR_ORDER_COMPLETED => 'Notify customer that their vendor order has been completed',
            self::DISPUTE_OPENED => 'Notify the vendor and administrators',
            self::DISPUTE_RESOLVED => 'Notify the customer and vendor',
        };
    }
}