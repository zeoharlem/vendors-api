<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Persistence;

use App\Modules\Notification\Domain\Contracts\OutboxEventPublisherInterface;
use App\Modules\Order\Domain\Repositories\OrderRepository;
use App\Shared\Enums\EventTypes;
use DomainException;
use JsonException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use Throwable;
use InvalidArgumentException;
use DateTimeImmutable;

final readonly class PdoOrderRepository implements OrderRepository
{
    public function __construct(
        private AdapterInterface $db,
        private readonly OutboxEventPublisherInterface $outboxEventPublisher,
    ) {
    }

    /**
     * Creates a parent order and vendor-specific suborders from the
     * customer's active cart.
     *
     * All writes, including inventory reservations, are atomic.
     *
     * @throws DomainException
     * @throws JsonException
     * @throws Throwable
     */
    public function checkout(
        string $customerId,
        array $checkoutData,
        string $idempotencyKey,
    ): array {
        $this->db->begin();

        try {
            /*
             * Serialize checkout attempts for this customer/key pair.
             * The database unique constraint remains the final safeguard.
             */
            $lockKey = $customerId . ':' . $idempotencyKey;

            $this->db->query(
                'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))',
                ['lock_key' => $lockKey],
            );

            // Idempotent replay: return the original order.
            $existing = $this->db->query(
                'SELECT id
                 FROM orders
                 WHERE customer_id = :customer_id
                   AND idempotency_key = :idempotency_key
                 LIMIT 1',
                [
                    'customer_id' => $customerId,
                    'idempotency_key' => $idempotencyKey,
                ],
            )->fetch();

            if ($existing) {
                $order = $this->findCustomerOrder($customerId, $existing['id']);

                $this->db->commit();

                if ($order === null) {
                    throw new DomainException('The existing order could not be retrieved.');
                }

                return $order;
            }

            // Lock the customer's active cart.
            $cart = $this->db->query(
                "SELECT id, currency
                 FROM carts
                 WHERE customer_id = :customer_id
                   AND status = 'active'
                 ORDER BY created_at DESC
                 LIMIT 1
                 FOR UPDATE",
                ['customer_id' => $customerId],
            )->fetch();

            if (!$cart) {
                throw new DomainException('You do not have an active cart.');
            }

            // Lock cart items and the associated catalogue records.
            $items = $this->db->query(
                "SELECT
                    ci.id AS cart_item_id,
                    ci.variant_id,
                    ci.quantity,

                    pv.product_id,
                    pv.sku,
                    pv.name AS variant_name,
                    pv.attributes,
                    pv.price_minor,
                    pv.currency AS variant_currency,
                    pv.status AS variant_status,

                    p.name AS product_name,
                    p.status AS product_status,
                    p.moderation_status,

                    s.id AS store_id,
                    s.status AS store_status,

                    vp.verification_status

                 FROM cart_items ci
                 INNER JOIN product_variants pv ON pv.id = ci.variant_id
                 INNER JOIN products p ON p.id = pv.product_id
                 INNER JOIN stores s ON s.id = p.store_id
                 INNER JOIN vendor_profiles vp ON vp.id = s.vendor_profile_id

                 WHERE ci.cart_id = :cart_id
                 ORDER BY pv.id, ci.id
                 FOR UPDATE OF ci, pv, p, s, vp",
                ['cart_id' => $cart['id']],
            )->fetchAll();

            if (!$items) {
                throw new DomainException('Your cart is empty.');
            }

            $currency = strtoupper(trim($cart['currency']));

            /*
             * Validate catalogue availability and currency before creating
             * any order records.
             */
            foreach ($items as $item) {
                if ($item['variant_status'] !== 'active') {
                    throw new DomainException(
                        "A variant in your cart is no longer available."
                    );
                }

                if (
                    $item['product_status'] !== 'published'
                    || $item['moderation_status'] !== 'approved'
                ) {
                    throw new DomainException(
                        "A product in your cart is no longer available for purchase."
                    );
                }

                if ($item['store_status'] !== 'active') {
                    throw new DomainException(
                        "A store associated with your cart is currently unavailable."
                    );
                }

                if ($item['verification_status'] !== 'verified') {
                    throw new DomainException(
                        "A vendor associated with your cart is not currently verified."
                    );
                }

                if (strtoupper($item['variant_currency']) !== $currency) {
                    throw new DomainException(
                        'All items in your cart must use the same currency.'
                    );
                }

                if ((int) $item['price_minor'] < 0 || (int) $item['quantity'] < 1) {
                    throw new DomainException('An item in your cart has invalid pricing or quantity.');
                }
            }

            /*
             * Lock inventory rows in a consistent order to reduce the
             * possibility of deadlocks between concurrent checkouts.
             */
            $inventoryByVariant = [];

            foreach ($items as $item) {
                $variantId = $item['variant_id'];

                if (isset($inventoryByVariant[$variantId])) {
                    continue;
                }

                $inventory = $this->db->query(
                    'SELECT variant_id, available_qty, reserved_qty
                     FROM inventory
                     WHERE variant_id = :variant_id
                     FOR UPDATE',
                    ['variant_id' => $variantId],
                )->fetch();

                if (!$inventory) {
                    throw new DomainException('Inventory is not configured for a cart item.');
                }

                $inventoryByVariant[$variantId] = $inventory;
            }

            // Validate stock after obtaining all inventory locks.
            foreach ($items as $item) {
                $inventory = $inventoryByVariant[$item['variant_id']];

                if ((int) $inventory['available_qty'] < (int) $item['quantity']) {
                    throw new DomainException(
                        "Insufficient stock for {$item['product_name']}."
                    );
                }
            }

            $shippingAddress = json_encode(
                $checkoutData['shipping_address'],
                JSON_THROW_ON_ERROR,
            );

            $billingAddress = $checkoutData['billing_address'] === null
                ? null
                : json_encode($checkoutData['billing_address'], JSON_THROW_ON_ERROR);

            // Group cart items by store.
            $groups = [];

            foreach ($items as $item) {
                $storeId = $item['store_id'];
                $groups[$storeId][] = $item;
            }

            $subtotalMinor = 0;

            foreach ($items as $item) {
                $subtotalMinor +=
                    (int) $item['price_minor'] * (int) $item['quantity'];
            }

            // Shipping, tax and discounts are zero until those modules are integrated.
            $orderTotalMinor = $subtotalMinor;

            $orderNumber = $this->generateOrderNumber('ORD');

            $orderResult = $this->db->query(
                "INSERT INTO orders (
                    order_number,
                    customer_id,
                    status,
                    payment_status,
                    currency,
                    subtotal_minor,
                    shipping_minor,
                    tax_minor,
                    discount_minor,
                    total_minor,
                    shipping_address,
                    billing_address,
                    customer_note,
                    idempotency_key
                ) VALUES (
                    :order_number,
                    :customer_id,
                    'pending_payment',
                    'pending',
                    :currency,
                    :subtotal_minor,
                    0,
                    0,
                    0,
                    :total_minor,
                    CAST(:shipping_address AS jsonb),
                    CAST(:billing_address AS jsonb),
                    :customer_note,
                    :idempotency_key
                )
                RETURNING id",
                [
                    'order_number' => $orderNumber,
                    'customer_id' => $customerId,
                    'currency' => $currency,
                    'subtotal_minor' => $subtotalMinor,
                    'total_minor' => $orderTotalMinor,
                    'shipping_address' => $shippingAddress,
                    'billing_address' => $billingAddress,
                    'customer_note' => $checkoutData['customer_note'],
                    'idempotency_key' => $idempotencyKey,
                ],
            )->fetch();

            if (!$orderResult) {
                throw new DomainException('Unable to create your order.');
            }

            $orderId = $orderResult['id'];

            // Record the parent order's initial status.
            $this->db->query(
                "INSERT INTO order_status_history (
                    order_id,
                    old_status,
                    new_status,
                    changed_by,
                    reason
                ) VALUES (
                    :order_id,
                    NULL,
                    'pending_payment',
                    :changed_by,
                    'Order created at checkout'
                )",
                [
                    'order_id' => $orderId,
                    'changed_by' => $customerId,
                ],
            );

            foreach ($groups as $storeId => $storeItems) {
                $vendorSubtotal = 0;

                foreach ($storeItems as $item) {
                    $vendorSubtotal +=
                        (int) $item['price_minor'] * (int) $item['quantity'];
                }

                $vendorOrderNumber = $this->generateOrderNumber('VND');

                $vendorOrderResult = $this->db->query(
                    "INSERT INTO vendor_orders (
                        order_id,
                        store_id,
                        order_number,
                        status,
                        currency,
                        subtotal_minor,
                        shipping_minor,
                        tax_minor,
                        discount_minor,
                        total_minor
                    ) VALUES (
                        :order_id,
                        :store_id,
                        :order_number,
                        'pending',
                        :currency,
                        :subtotal_minor,
                        0,
                        0,
                        0,
                        :total_minor
                    )
                    RETURNING id",
                    [
                        'order_id' => $orderId,
                        'store_id' => $storeId,
                        'order_number' => $vendorOrderNumber,
                        'currency' => $currency,
                        'subtotal_minor' => $vendorSubtotal,
                        'total_minor' => $vendorSubtotal,
                    ],
                )->fetch();

                if (!$vendorOrderResult) {
                    throw new DomainException('Unable to create a vendor order.');
                }

                $vendorOrderId = $vendorOrderResult['id'];

                $this->db->query(
                    "INSERT INTO order_status_history (
                        order_id,
                        vendor_order_id,
                        old_status,
                        new_status,
                        changed_by,
                        reason
                    ) VALUES (
                        :order_id,
                        :vendor_order_id,
                        NULL,
                        'pending',
                        :changed_by,
                        'Vendor order created at checkout'
                    )",
                    [
                        'order_id' => $orderId,
                        'vendor_order_id' => $vendorOrderId,
                        'changed_by' => $customerId,
                    ],
                );

                foreach ($storeItems as $item) {
                    $quantity = (int) $item['quantity'];
                    $unitPrice = (int) $item['price_minor'];
                    $lineTotal = $unitPrice * $quantity;

                    $attributes = $item['attributes'];

                    if (is_string($attributes)) {
                        $attributes = json_decode($attributes, true, 512, JSON_THROW_ON_ERROR);
                    }

                    $attributesJson = json_encode(
                        $attributes ?? [],
                        JSON_THROW_ON_ERROR,
                    );

                    $orderItemResult = $this->db->query(
                        "INSERT INTO order_items (
                            order_id,
                            vendor_order_id,
                            product_variant_id,
                            product_name,
                            variant_name,
                            sku,
                            attributes,
                            quantity,
                            unit_price_minor,
                            discount_minor,
                            tax_minor,
                            line_total_minor,
                            currency
                        ) VALUES (
                            :order_id,
                            :vendor_order_id,
                            :variant_id,
                            :product_name,
                            :variant_name,
                            :sku,
                            CAST(:attributes AS jsonb),
                            :quantity,
                            :unit_price_minor,
                            0,
                            0,
                            :line_total_minor,
                            :currency
                        )
                        RETURNING id",
                        [
                            'order_id' => $orderId,
                            'vendor_order_id' => $vendorOrderId,
                            'variant_id' => $item['variant_id'],
                            'product_name' => $item['product_name'],
                            'variant_name' => $item['variant_name'],
                            'sku' => $item['sku'],
                            'attributes' => $attributesJson,
                            'quantity' => $quantity,
                            'unit_price_minor' => $unitPrice,
                            'line_total_minor' => $lineTotal,
                            'currency' => $currency,
                        ],
                    )->fetch();

                    if (!$orderItemResult) {
                        throw new DomainException('Unable to create an order item.');
                    }

                    $orderItemId = $orderItemResult['id'];

                    // Reserve inventory atomically.
                    $inventory = $inventoryByVariant[$item['variant_id']];

                    $availableBefore = (int) $inventory['available_qty'];
                    $reservedBefore = (int) $inventory['reserved_qty'];

                    $availableAfter = $availableBefore - $quantity;
                    $reservedAfter = $reservedBefore + $quantity;

                    $this->db->query(
                        'UPDATE inventory
                         SET available_qty = :available_qty,
                             reserved_qty = :reserved_qty,
                             updated_at = NOW()
                         WHERE variant_id = :variant_id',
                        [
                            'available_qty' => $availableAfter,
                            'reserved_qty' => $reservedAfter,
                            'variant_id' => $item['variant_id'],
                        ],
                    );

                    // Keep the in-memory values accurate if a variant has multiple cart lines.
                    $inventoryByVariant[$item['variant_id']]['available_qty'] = $availableAfter;
                    $inventoryByVariant[$item['variant_id']]['reserved_qty'] = $reservedAfter;

                    $this->db->query(
                        "INSERT INTO inventory_reservations (
                            order_id,
                            order_item_id,
                            variant_id,
                            quantity,
                            status,
                            expires_at
                        ) VALUES (
                            :order_id,
                            :order_item_id,
                            :variant_id,
                            :quantity,
                            'reserved',
                            NOW() + INTERVAL '30 minutes'
                        )",
                        [
                            'order_id' => $orderId,
                            'order_item_id' => $orderItemId,
                            'variant_id' => $item['variant_id'],
                            'quantity' => $quantity,
                        ],
                    );

                    $this->db->query(
                        "INSERT INTO inventory_movements (
                            variant_id,
                            movement_type,
                            quantity_delta,
                            available_before,
                            available_after,
                            reserved_before,
                            reserved_after,
                            reason,
                            reference_type,
                            reference_id,
                            actor_user_id
                        ) VALUES (
                            :variant_id,
                            'reservation',
                            :quantity_delta,
                            :available_before,
                            :available_after,
                            :reserved_before,
                            :reserved_after,
                            'Checkout inventory reservation',
                            'order',
                            :reference_id,
                            :actor_user_id
                        )",
                        [
                            'variant_id' => $item['variant_id'],
                            'quantity_delta' => -$quantity,
                            'available_before' => $availableBefore,
                            'available_after' => $availableAfter,
                            'reserved_before' => $reservedBefore,
                            'reserved_after' => $reservedAfter,
                            'reference_id' => $orderId,
                            'actor_user_id' => $customerId,
                        ],
                    );
                }
            }

            // Convert the cart only after all order and reservation writes succeed.
            $this->db->query(
                "UPDATE carts
                 SET status = 'converted',
                     converted_order_id = :order_id,
                     updated_at = NOW()
                 WHERE id = :cart_id",
                [
                    'order_id' => $orderId,
                    'cart_id' => $cart['id'],
                ],
            );

            $order = $this->findCustomerOrder($customerId, $orderId);

            if ($order === null) {
                throw new DomainException('The order was created but could not be retrieved.');
            }

            /**
             * Record the order.placed event in the same database transaction.
             * The outbox dispatcher will publish it after this transaction commits. 
             **/
            $this->outboxEventPublisher->publish(
                eventType: EventTypes::ORDER_PLACED->value,
                aggregateType: 'order',
                aggregateId: $orderId,
                idempotencyKey: EventTypes::ORDER_PLACED->value . ':' . $orderId,
                payload: [
                    'order_id' => $orderId,
                    'customer_id' => $customerId,
                    'order_number' => $orderNumber,
                    'currency' => $currency,
                    'subtotal_minor' => $subtotalMinor,
                    'total_minor' => $orderTotalMinor,
                ],
            );
            /*** Publish Event */

            $this->db->commit();

            return $order;
        } catch (Throwable $exception) {
            try {
                $this->db->rollback();
            } catch (Throwable) {
                // Preserve the original exception.
            }

            throw $exception;
        }
    }

    public function findCustomerOrder(
        string $customerId,
        string $orderId,
    ): ?array {
        $order = $this->db->query(
            'SELECT
                id,
                order_number,
                status,
                payment_status,
                currency,
                subtotal_minor,
                shipping_minor,
                tax_minor,
                discount_minor,
                total_minor,
                shipping_address,
                billing_address,
                customer_note,
                paid_at,
                created_at,
                updated_at
             FROM orders
             WHERE id = :order_id
               AND customer_id = :customer_id
             LIMIT 1',
            [
                'order_id' => $orderId,
                'customer_id' => $customerId,
            ],
        )->fetch();

        if (!$order) {
            return null;
        }

        $vendorOrders = $this->db->query(
            'SELECT
                id,
                order_number,
                store_id,
                status,
                currency,
                subtotal_minor,
                shipping_minor,
                tax_minor,
                discount_minor,
                total_minor,
                accepted_at,
                shipped_at,
                delivered_at,
                customer_confirmed_at,
                acceptance_deadline,
                completed_at,
                cancelled_at,
                shipping_provider,
                tracking_number,
                tracking_url,
                created_at,
                updated_at
             FROM vendor_orders
             WHERE order_id = :order_id
             ORDER BY created_at ASC',
            ['order_id' => $orderId],
        )->fetchAll();

        foreach ($vendorOrders as &$vendorOrder) {
            $vendorOrder['items'] = $this->db->query(
                'SELECT
                    id,
                    product_variant_id,
                    product_name,
                    variant_name,
                    sku,
                    attributes,
                    quantity,
                    unit_price_minor,
                    discount_minor,
                    tax_minor,
                    line_total_minor,
                    currency,
                    created_at
                 FROM order_items
                 WHERE vendor_order_id = :vendor_order_id
                 ORDER BY created_at ASC',
                ['vendor_order_id' => $vendorOrder['id']],
            )->fetchAll();
        }
        unset($vendorOrder);

        $order['vendor_orders'] = $vendorOrders;

        return $order;
    }


    public function cancelAdminOrder(
        string $orderId,
        string $adminId,
        string $reason
    ): array {
        $this->db->begin();

        try {
            /*
             * 1. Lock the parent order first.
             *
             * Payment confirmation follows this same locking order.
             */
            $order = $this->db->query(
                <<<'SQL'
                SELECT
                    id,
                    order_number,
                    status,
                    payment_status,
                    paid_at
                FROM public.orders
                WHERE id = :order_id
                FOR UPDATE
            SQL,
                ['order_id' => $orderId]
            )->fetch();

            if ($order === false) {
                throw new DomainException('Order not found.');
            }

            if ($order['status'] === 'cancelled') {
                throw new DomainException(
                    'This order has already been cancelled.'
                );
            }

            if (
                $order['status'] !== 'pending_payment'
                || !in_array(
                    $order['payment_status'],
                    ['pending', 'failed'],
                    true
                )
                || $order['paid_at'] !== null
            ) {
                throw new DomainException(
                    'Only unpaid orders awaiting payment can be cancelled through this action.'
                );
            }

            /*
             * 2. Lock every payment attempt for this order.
             *
             * A pending or initialized provider payment may still succeed
             * asynchronously. Do not cancel while such an attempt exists.
             *
             * Failed attempts are safe to proceed past.
             */
            $payments = $this->db->query(
                <<<'SQL'
                SELECT id, status
                FROM public.payments
                WHERE order_id = :order_id
                ORDER BY id
                FOR UPDATE
            SQL,
                ['order_id' => $orderId]
            )->fetchAll();

            foreach ($payments as $payment) {
                if ($payment['status'] === 'success') {
                    throw new DomainException(
                        'This order has a successful payment and requires the refund workflow.'
                    );
                }

                if (
                    in_array(
                        $payment['status'],
                        ['pending', 'initialized'],
                        true
                    )
                ) {
                    throw new DomainException(
                        'This order has an active payment attempt. '
                        . 'Verify its payment status before cancelling the order.'
                    );
                }

                if ($payment['status'] !== 'failed') {
                    throw new DomainException(
                        'An order payment has an unexpected status. '
                        . 'Cancellation requires payment reconciliation.'
                    );
                }
            }

            /*
             * 3. Lock vendor orders and ensure fulfilment has not started.
             */
            $vendorOrders = $this->db->query(
                <<<'SQL'
                SELECT id, status
                FROM public.vendor_orders
                WHERE order_id = :order_id
                ORDER BY id
                FOR UPDATE
            SQL,
                ['order_id' => $orderId]
            )->fetchAll();

            if ($vendorOrders === []) {
                throw new DomainException(
                    'The order has no vendor orders to cancel.'
                );
            }

            foreach ($vendorOrders as $vendorOrder) {
                if ($vendorOrder['status'] !== 'pending') {
                    throw new DomainException(
                        'The order cannot be cancelled because vendor fulfilment has already progressed.'
                    );
                }
            }

            /*
             * 4. Lock ALL inventory reservations for this order.
             *
             * We intentionally do not filter by status here.
             * A committed or otherwise unexpected reservation must not
             * be silently ignored during cancellation.
             */
            $reservations = $this->db->query(
                <<<'SQL'
                SELECT
                    id,
                    variant_id,
                    quantity,
                    status
                FROM public.inventory_reservations
                WHERE order_id = :order_id
                ORDER BY variant_id, id
                FOR UPDATE
            SQL,
                ['order_id' => $orderId]
            )->fetchAll();

            if ($reservations === []) {
                throw new DomainException(
                    'No inventory reservations were found. '
                    . 'Order cancellation requires inventory reconciliation.'
                );
            }

            foreach ($reservations as $reservation) {
                if ($reservation['status'] !== 'reserved') {
                    throw new DomainException(
                        'An inventory reservation is no longer in the reserved state. '
                        . 'Order cancellation requires inventory reconciliation.'
                    );
                }
            }

            /*
             * 5. Release each inventory reservation.
             */
            foreach ($reservations as $reservation) {
                $variantId = $reservation['variant_id'];
                $quantity = (int) $reservation['quantity'];

                if ($quantity <= 0) {
                    throw new DomainException(
                        'An inventory reservation has an invalid quantity.'
                    );
                }

                /*
                 * Lock the inventory row before modifying quantities.
                 */
                $inventory = $this->db->query(
                    <<<'SQL'
                    SELECT
                        variant_id,
                        available_qty,
                        reserved_qty
                    FROM public.inventory
                    WHERE variant_id = :variant_id
                    FOR UPDATE
                SQL,
                    ['variant_id' => $variantId]
                )->fetch();

                if ($inventory === false) {
                    throw new DomainException(
                        'Inventory configuration is missing for variant: '
                        . $variantId
                    );
                }

                $availableBefore = (int) $inventory['available_qty'];
                $reservedBefore = (int) $inventory['reserved_qty'];

                if ($reservedBefore < $quantity) {
                    throw new DomainException(
                        'Reserved inventory is inconsistent. Cancellation was stopped.'
                    );
                }

                $availableAfter = $availableBefore + $quantity;
                $reservedAfter = $reservedBefore - $quantity;

                /*
                 * Restore available stock and release reserved stock.
                 */
                $this->db->query(
                    <<<'SQL'
                    UPDATE public.inventory
                    SET
                        available_qty = :available_qty,
                        reserved_qty = :reserved_qty,
                        updated_at = NOW()
                    WHERE variant_id = :variant_id
                SQL,
                    [
                        'available_qty' => $availableAfter,
                        'reserved_qty' => $reservedAfter,
                        'variant_id' => $variantId,
                    ]
                );

                /*
                 * Mark the reservation as released.
                 *
                 * Use updated_at rather than released_at because the
                 * latter has not been established in the current schema.
                 */
                $this->db->query(
                    <<<'SQL'
                    UPDATE public.inventory_reservations
                    SET
                        status = 'released',
                        updated_at = NOW()
                    WHERE id = :reservation_id
                      AND status = 'reserved'
                SQL,
                    ['reservation_id' => $reservation['id']]
                );

                /*
                 * Record the release using the existing reservation
                 * movement type. The reason identifies the operation.
                 */
                $this->db->query(
                    <<<'SQL'
                    INSERT INTO public.inventory_movements (
                        variant_id,
                        movement_type,
                        quantity_delta,
                        available_before,
                        available_after,
                        reserved_before,
                        reserved_after,
                        reason,
                        reference_type,
                        reference_id,
                        actor_user_id
                    ) VALUES (
                        :variant_id,
                        'reservation',
                        :quantity_delta,
                        :available_before,
                        :available_after,
                        :reserved_before,
                        :reserved_after,
                        :reason,
                        'order',
                        :reference_id,
                        :actor_user_id
                    )
                SQL,
                    [
                        'variant_id' => $variantId,
                        'quantity_delta' => $quantity,
                        'available_before' => $availableBefore,
                        'available_after' => $availableAfter,
                        'reserved_before' => $reservedBefore,
                        'reserved_after' => $reservedAfter,
                        'reason' => 'Admin released inventory reservation after order cancellation.',
                        'reference_id' => $orderId,
                        'actor_user_id' => $adminId,
                    ]
                );
            }

            /*
             * 6. Cancel vendor orders and record their status history.
             */
            foreach ($vendorOrders as $vendorOrder) {
                $this->db->query(
                    <<<'SQL'
                    UPDATE public.vendor_orders
                    SET
                        status = 'cancelled',
                        cancelled_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :vendor_order_id
                      AND status = 'pending'
                SQL,
                    ['vendor_order_id' => $vendorOrder['id']]
                );

                $this->db->query(
                    <<<'SQL'
                    INSERT INTO public.order_status_history (
                        order_id,
                        vendor_order_id,
                        old_status,
                        new_status,
                        changed_by,
                        reason,
                        metadata,
                        created_at
                    ) VALUES (
                        :order_id,
                        :vendor_order_id,
                        :old_status,
                        'cancelled',
                        :changed_by,
                        :reason,
                        CAST(:metadata AS JSONB),
                        NOW()
                    )
                SQL,
                    [
                        'order_id' => $orderId,
                        'vendor_order_id' => $vendorOrder['id'],
                        'old_status' => $vendorOrder['status'],
                        'changed_by' => $adminId,
                        'reason' => $reason,
                        'metadata' => json_encode([
                            'cancellation_source' => 'admin',
                        ], JSON_THROW_ON_ERROR),
                    ]
                );
            }

            /*
             * 7. Cancel the parent order.
             */
            $this->db->query(
                <<<'SQL'
                UPDATE public.orders
                SET
                    status = 'cancelled',
                    updated_at = NOW()
                WHERE id = :order_id
                  AND status = 'pending_payment'
            SQL,
                ['order_id' => $orderId]
            );

            /*
             * Record the parent order status transition.
             */
            $this->db->query(
                <<<'SQL'
                INSERT INTO public.order_status_history (
                    order_id,
                    vendor_order_id,
                    old_status,
                    new_status,
                    changed_by,
                    reason,
                    metadata,
                    created_at
                ) VALUES (
                    :order_id,
                    NULL,
                    :old_status,
                    'cancelled',
                    :changed_by,
                    :reason,
                    CAST(:metadata AS JSONB),
                    NOW()
                )
            SQL,
                [
                    'order_id' => $orderId,
                    'old_status' => $order['status'],
                    'changed_by' => $adminId,
                    'reason' => $reason,
                    'metadata' => json_encode([
                        'cancellation_source' => 'admin',
                        'inventory_reservations_released' => count($reservations),
                    ], JSON_THROW_ON_ERROR),
                ]
            );

            /*
             * 8. Publish the cancellation event to the transactional outbox.
             */
            $this->outboxEventPublisher->publish(
                eventType: EventTypes::ORDER_CANCELLED->value,
                aggregateType: 'order',
                aggregateId: $orderId,
                idempotencyKey: EventTypes::ORDER_CANCELLED->value . ':' . $orderId,
                payload: [
                    'order_id' => $orderId,
                    'order_number' => $order['order_number'],
                    'admin_id' => $adminId,
                    'reason' => $reason,
                    'inventory_reservations_released' => count($reservations),
                ],
            );

            /*
             * 9. Retrieve the updated order before committing.
             *
             * If retrieval fails, the transaction can still roll back.
             */
            $cancelledOrder = $this->findAdminOrder($orderId);

            if ($cancelledOrder === null) {
                throw new DomainException(
                    'The cancelled order could not be retrieved.'
                );
            }

            $this->db->commit();

            return $cancelledOrder;

        } catch (Throwable $exception) {
            try {
                $this->db->rollback();
            } catch (Throwable) {
                // Preserve the original exception.
            }

            throw $exception;
        }
    }



    /**
     * Lists parent customer orders for administrative use.
     *
     * Supports pagination, customer/order search, status filters
     * and date filtering.
     *
     * @return array{
     *     items: array,
     *     pagination: array{
     *         page: int,
     *         per_page: int,
     *         total: int,
     *         total_pages: int
     *     }
     * }
     */
    public function listAdminOrders(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(1, min($perPage, 100));

        $offset = ($page - 1) * $perPage;

        $where = [];
        $bind = [];

        /*
         * Search by parent order number or customer details.
         */
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            if (mb_strlen($search) > 150) {
                throw new InvalidArgumentException(
                    'Search cannot exceed 150 characters.'
                );
            }

            $where[] = "(
            o.order_number ILIKE :search_order_number
            OR u.first_name ILIKE :search_first_name
            OR u.last_name ILIKE :search_last_name
            OR u.email ILIKE :search_email
            OR u.phone ILIKE :search_phone
            OR CONCAT_WS(' ', u.first_name, u.last_name)
                ILIKE :search_full_name
        )";

            $searchTerm = '%' . $search . '%';

            $bind['search_order_number'] = $searchTerm;
            $bind['search_first_name'] = $searchTerm;
            $bind['search_last_name'] = $searchTerm;
            $bind['search_email'] = $searchTerm;
            $bind['search_phone'] = $searchTerm;
            $bind['search_full_name'] = $searchTerm;
        }

        /*
         * Filter by parent order status.
         */
        $status = trim((string) ($filters['status'] ?? ''));

        if ($status !== '') {
            if (mb_strlen($status) > 50) {
                throw new InvalidArgumentException(
                    'Invalid order status filter.'
                );
            }

            $where[] = 'o.status = :order_status';
            $bind['order_status'] = $status;
        }

        /*
         * Filter by payment status independently
         * from the parent order status.
         */
        $paymentStatus = trim(
            (string) ($filters['payment_status'] ?? '')
        );

        if ($paymentStatus !== '') {
            if (mb_strlen($paymentStatus) > 50) {
                throw new InvalidArgumentException(
                    'Invalid payment status filter.'
                );
            }

            $where[] = 'o.payment_status = :payment_status';
            $bind['payment_status'] = $paymentStatus;
        }

        /*
         * Validate and apply date filters.
         */
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));

        $validateDate = static function (string $date): bool {
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $date
            );

            return $parsed !== false
                && $parsed->format('Y-m-d') === $date;
        };

        if ($dateFrom !== '') {
            if (!$validateDate($dateFrom)) {
                throw new InvalidArgumentException(
                    'date_from must use YYYY-MM-DD format.'
                );
            }

            $where[] = 'o.created_at >= CAST(:date_from AS date)';
            $bind['date_from'] = $dateFrom;
        }

        if ($dateTo !== '') {
            if (!$validateDate($dateTo)) {
                throw new InvalidArgumentException(
                    'date_to must use YYYY-MM-DD format.'
                );
            }

            $where[] = "
            o.created_at < (
                CAST(:date_to AS date) + INTERVAL '1 day'
            )
        ";

            $bind['date_to'] = $dateTo;
        }

        if (
            $dateFrom !== ''
            && $dateTo !== ''
            && $dateFrom > $dateTo
        ) {
            throw new InvalidArgumentException(
                'date_from cannot be later than date_to.'
            );
        }

        $whereSql = $where
            ? 'WHERE ' . implode(' AND ', $where)
            : '';

        /*
         * Count parent orders.
         *
         * This query intentionally does not join vendor_orders
         * directly, avoiding duplicate parent order rows.
         */
        $countSql = "
            SELECT COUNT(*) AS total
            FROM orders o
            INNER JOIN users u ON u.id = o.customer_id
            {$whereSql}
        ";

        $countResult = $this->db->query(
            $countSql,
            $bind
        )->fetch();

        $total = (int) ($countResult['total'] ?? 0);

        /*
         * Aggregate vendor orders separately.
         *
         * Each parent order appears only once in the result.
         */
        $sql = <<<'SQL'
            WITH vendor_order_summary AS (
                SELECT
                    order_id,
                    COUNT(*)::integer AS vendor_order_count,
                    (
                        SELECT jsonb_object_agg(status, status_count)
                        FROM (
                            SELECT
                                status,
                                COUNT(*)::integer AS status_count
                            FROM vendor_orders vo2
                            WHERE vo2.order_id = vo.order_id
                            GROUP BY status
                        ) status_counts
                    ) AS vendor_order_statuses
                FROM vendor_orders vo
                GROUP BY order_id
            ),
            item_summary AS (
                SELECT
                    order_id,
                    COALESCE(SUM(quantity), 0)::integer AS item_count
                FROM order_items
                GROUP BY order_id
            )
            SELECT
                o.id,
                o.order_number,
                o.status,
                o.payment_status,
                o.currency,
                o.subtotal_minor,
                o.shipping_minor,
                o.tax_minor,
                o.discount_minor,
                o.total_minor,
                o.paid_at,
                o.created_at,
                o.updated_at,

                jsonb_build_object(
                    'id', u.id,
                    'first_name', u.first_name,
                    'last_name', u.last_name,
                    'email', u.email,
                    'phone', u.phone
                ) AS customer,

                COALESCE(vos.vendor_order_count, 0)::integer
                    AS vendor_order_count,

                COALESCE(vos.vendor_order_statuses, '{}'::jsonb)
                    AS vendor_order_statuses,

                COALESCE(its.item_count, 0)::integer
                    AS item_count

            FROM orders o
            INNER JOIN users u ON u.id = o.customer_id
            LEFT JOIN vendor_order_summary vos ON vos.order_id = o.id
            LEFT JOIN item_summary its ON its.order_id = o.id
            /* ORDER_FILTERS */
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT CAST(:limit AS integer)
            OFFSET CAST(:offset AS integer)
        SQL;

        $sql = str_replace('/* ORDER_FILTERS */', $whereSql, $sql);

        $queryBind = $bind;

        $queryBind['limit'] = $perPage;
        $queryBind['offset'] = $offset;

        $rows = $this->db->query($sql, $queryBind)->fetchAll();

        /*
         * Normalize database values for the API response.
         */
        foreach ($rows as &$row) {
            $row['vendor_order_count'] = (int) $row['vendor_order_count'];
            $row['item_count'] = (int) $row['item_count'];

            $row['subtotal_minor'] = (int) $row['subtotal_minor'];
            $row['shipping_minor'] = (int) $row['shipping_minor'];
            $row['tax_minor'] = (int) $row['tax_minor'];
            $row['discount_minor'] = (int) $row['discount_minor'];
            $row['total_minor'] = (int) $row['total_minor'];

            if (is_string($row['customer'])) {
                $row['customer'] = json_decode(
                    $row['customer'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }

            if (is_string($row['vendor_order_statuses'])) {
                $row['vendor_order_statuses'] = json_decode(
                    $row['vendor_order_statuses'],
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }
        }

        unset($row);

        return [
            'items' => $rows,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }


    public function findAdminOrder(string $orderId): ?array
    {
        $sql = <<<'SQL'
            SELECT customer_id
            FROM orders
            WHERE id = :order_id
            LIMIT 1
        SQL;

        $order = $this->db->query(
            $sql,
            ['order_id' => $orderId],
        )->fetch();

        if (!$order) {
            return null;
        }

        $details = $this->findCustomerOrder(
            $order['customer_id'],
            $orderId,
        );

        if ($details === null) {
            return null;
        }

        $customerSql = <<<'SQL'
            SELECT
                id,
                first_name,
                last_name,
                email,
                phone
            FROM users
            WHERE id = :customer_id
            LIMIT 1
        SQL;

        $customer = $this->db->query(
            $customerSql,
            ['customer_id' => $order['customer_id']],
        )->fetch();

        $details['customer'] = $customer ?: null;

        foreach (['shipping_address', 'billing_address'] as $field) {
            if (is_string($details[$field] ?? null)) {
                $details[$field] = json_decode(
                    $details[$field],
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            }
        }

        foreach ($details['vendor_orders'] as &$vendorOrder) {
            foreach ($vendorOrder['items'] as &$item) {
                if (is_string($item['attributes'] ?? null)) {
                    $item['attributes'] = json_decode(
                        $item['attributes'],
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                }
            }

            unset($item);
        }

        unset($vendorOrder);

        $historySql = <<<'SQL'
            SELECT
                osh.order_id,
                osh.vendor_order_id,
                vo.order_number AS vendor_order_number,
                osh.old_status,
                osh.new_status,
                osh.changed_by,
                COALESCE(
                    NULLIF(
                        BTRIM(CONCAT_WS(' ', u.first_name, u.last_name)),
                        ''
                    ),
                    u.email,
                    'System'
                ) AS changed_by_name,
                osh.reason,
                osh.metadata,
                osh.created_at
            FROM public.order_status_history osh
            LEFT JOIN public.users u
                ON u.id = osh.changed_by
            LEFT JOIN public.vendor_orders vo
                ON vo.id = osh.vendor_order_id
            WHERE osh.order_id = :order_id
            ORDER BY
                osh.created_at ASC,
                osh.vendor_order_id NULLS FIRST
        SQL;

        $historyRows = $this->db->query(
            $historySql,
            ['order_id' => $orderId]
        )->fetchAll();

        $details['status_history'] = array_map(
            static function (array $entry): array {
                if (is_string($entry['metadata'] ?? null)) {
                    $entry['metadata'] = json_decode($entry['metadata'], true, 512, JSON_THROW_ON_ERROR);
                }

                return $entry;
            },
            $historyRows ?: []
        );

        return $details;
    }

    private function generateOrderNumber(string $prefix): string
    {
        return $prefix
            . '-'
            . date('Ymd')
            . '-'
            . strtoupper(bin2hex(random_bytes(5)));
    }
}