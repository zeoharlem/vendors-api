<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Persistence;

use App\Modules\Order\Domain\Repositories\OrderRepository;
use DomainException;
use JsonException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use Throwable;

final readonly class PdoOrderRepository implements OrderRepository
{
    public function __construct(
        private AdapterInterface $db,
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

    private function generateOrderNumber(string $prefix): string
    {
        return $prefix
            . '-'
            . date('Ymd')
            . '-'
            . strtoupper(bin2hex(random_bytes(5)));
    }
}