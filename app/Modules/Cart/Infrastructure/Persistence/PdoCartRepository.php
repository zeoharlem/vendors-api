<?php

declare(strict_types=1);

namespace App\Modules\Cart\Infrastructure\Persistence;

use App\Modules\Cart\Domain\Repositories\CartRepository;
use DomainException;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;
use Throwable;

final readonly class PdoCartRepository implements CartRepository
{
    public function __construct(
        private AdapterInterface $db
    ) {
    }

    public function getOrCreateActiveCart(
        string $customerId,
        string $currency = 'NGN'
    ): array {
        $this->db->query(
            <<<'SQL'
            INSERT INTO public.carts (customer_id, currency)
            VALUES (?, ?)
            ON CONFLICT (customer_id) WHERE status = 'active'
            DO NOTHING
            SQL,
            [$customerId, strtoupper($currency)]
        );

        $cart = $this->db->query(
            <<<'SQL'
            SELECT id, customer_id, status, currency, expires_at,
                   created_at, updated_at
            FROM public.carts
            WHERE customer_id = ?
              AND status = 'active'
            LIMIT 1
            SQL,
            [$customerId]
        )->fetch();

        if (!$cart) {
            throw new RuntimeException('Unable to create or retrieve cart.');
        }

        return $cart;
    }

    public function findActiveCart(string $customerId): ?array
    {
        $cart = $this->db->query(
            <<<'SQL'
            SELECT id, customer_id, status, currency, expires_at,
                   created_at, updated_at
            FROM public.carts
            WHERE customer_id = ?
              AND status = 'active'
            LIMIT 1
            SQL,
            [$customerId]
        )->fetch();

        return $cart ?: null;
    }

    public function listItems(string $customerId): array
    {
        $result = $this->db->query(
            <<<'SQL'
            SELECT
                ci.id AS cart_item_id,
                ci.cart_id,
                ci.variant_id,
                ci.quantity,
                ci.created_at,
                ci.updated_at,
                pv.product_id,
                pv.sku,
                pv.name AS variant_name,
                pv.attributes,
                pv.price_minor,
                pv.compare_at_minor,
                pv.currency,
                p.name AS product_name,
                p.slug AS product_slug,
                p.status AS product_status,
                p.moderation_status,
                s.id AS store_id,
                s.name AS store_name,
                i.available_qty,
                pv.status AS variant_status,
                s.status AS store_status,
                vp.verification_status,
                (pv.price_minor * ci.quantity) AS line_total_minor
            FROM public.cart_items ci
            INNER JOIN public.carts c
                ON c.id = ci.cart_id
            INNER JOIN public.product_variants pv
                ON pv.id = ci.variant_id
            INNER JOIN public.products p
                ON p.id = pv.product_id
            INNER JOIN public.stores s
                ON s.id = p.store_id
            INNER JOIN public.vendor_profiles vp
                ON vp.id = s.vendor_profile_id
            LEFT JOIN public.inventory i
                ON i.variant_id = pv.id
            WHERE c.customer_id = ?
              AND c.status = 'active'
            ORDER BY ci.created_at ASC
            SQL,
            [$customerId]
        );

        return $result->fetchAll();
    }

    public function addItem(
        string $customerId,
        string $variantId,
        int $quantity
    ): array {
        $this->db->begin();

        try {
            $variant = $this->db->query(
                <<<'SQL'
                SELECT
                    pv.id,
                    pv.currency,
                    pv.price_minor,
                    pv.status AS variant_status,
                    p.status AS product_status,
                    p.moderation_status,
                    s.status AS store_status,
                    vp.verification_status,
                    COALESCE(i.available_qty, 0) AS available_qty
                FROM public.product_variants pv
                INNER JOIN public.products p
                    ON p.id = pv.product_id
                INNER JOIN public.stores s
                    ON s.id = p.store_id
                INNER JOIN public.vendor_profiles vp
                    ON vp.id = s.vendor_profile_id
                LEFT JOIN public.inventory i
                    ON i.variant_id = pv.id
                WHERE pv.id = ?
                LIMIT 1
                SQL,
                [$variantId]
            )->fetch();

            if (!$variant) {
                throw new DomainException('Product variant not found.');
            }

            if (
                $variant['variant_status'] !== 'active'
                || $variant['product_status'] !== 'published'
                || $variant['moderation_status'] !== 'approved'
                || $variant['store_status'] !== 'active'
                || $variant['verification_status'] !== 'verified'
            ) {
                throw new DomainException(
                    'This product is not currently available for purchase.'
                );
            }

            if ((int) $variant['available_qty'] < 1) {
                throw new DomainException('This product is out of stock.');
            }

            $cart = $this->getOrCreateActiveCart(
                $customerId,
                (string) $variant['currency']
            );

            if (
                strtoupper((string) $cart['currency'])
                !== strtoupper((string) $variant['currency'])
            ) {
                throw new DomainException(
                    'Products with different currencies cannot be combined in one cart.'
                );
            }

            $existing = $this->db->query(
                <<<'SQL'
                SELECT id, quantity
                FROM public.cart_items
                WHERE cart_id = ?
                  AND variant_id = ?
                LIMIT 1
                FOR UPDATE
                SQL,
                [$cart['id'], $variantId]
            )->fetch();

            $newQuantity = (int) $quantity;

            if ($existing) {
                $newQuantity += (int) $existing['quantity'];
            }

            if ($newQuantity > (int) $variant['available_qty']) {
                throw new DomainException(
                    'Requested quantity exceeds currently available stock.'
                );
            }

            if ($existing) {
                $this->db->query(
                    <<<'SQL'
                    UPDATE public.cart_items
                    SET quantity = ?, updated_at = NOW()
                    WHERE id = ?
                    SQL,
                    [$newQuantity, $existing['id']]
                );
            } else {
                $this->db->query(
                    <<<'SQL'
                    INSERT INTO public.cart_items
                        (cart_id, variant_id, quantity)
                    VALUES (?, ?, ?)
                    SQL,
                    [$cart['id'], $variantId, $newQuantity]
                );
            }

            $this->db->query(
                <<<'SQL'
                UPDATE public.carts
                SET updated_at = NOW()
                WHERE id = ?
                SQL,
                [$cart['id']]
            );

            $this->db->commit();

            return [
                'cart_id' => $cart['id'],
                'variant_id' => $variantId,
                'quantity' => $newQuantity,
                'currency' => $variant['currency'],
                'unit_price_minor' => (int) $variant['price_minor'],
                'line_total_minor' =>
                    (int) $variant['price_minor'] * $newQuantity,
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function updateItemQuantity(
        string $customerId,
        string $itemId,
        int $quantity
    ): array {
        $this->db->begin();

        try {
            $item = $this->db->query(
                <<<'SQL'
                SELECT
                    ci.id,
                    ci.cart_id,
                    ci.variant_id,
                    pv.currency,
                    pv.price_minor,
                    COALESCE(i.available_qty, 0) AS available_qty,
                    c.currency AS cart_currency
                FROM public.cart_items ci
                INNER JOIN public.carts c
                    ON c.id = ci.cart_id
                INNER JOIN public.product_variants pv
                    ON pv.id = ci.variant_id
                LEFT JOIN public.inventory i
                    ON i.variant_id = pv.id
                WHERE ci.id = ?
                  AND c.customer_id = ?
                  AND c.status = 'active'
                FOR UPDATE OF ci
                SQL,
                [$itemId, $customerId]
            )->fetch();

            if (!$item) {
                throw new DomainException('Cart item not found.');
            }

            if (
                strtoupper((string) $item['currency'])
                !== strtoupper((string) $item['cart_currency'])
            ) {
                throw new DomainException(
                    'Cart currency does not match the product currency.'
                );
            }

            if ($quantity > (int) $item['available_qty']) {
                throw new DomainException(
                    'Requested quantity exceeds currently available stock.'
                );
            }

            $this->db->query(
                <<<'SQL'
                UPDATE public.cart_items
                SET quantity = ?, updated_at = NOW()
                WHERE id = ?
                SQL,
                [$quantity, $itemId]
            );

            $this->db->query(
                <<<'SQL'
                UPDATE public.carts
                SET updated_at = NOW()
                WHERE id = ?
                SQL,
                [$item['cart_id']]
            );

            $this->db->commit();

            return [
                'cart_item_id' => $itemId,
                'variant_id' => $item['variant_id'],
                'quantity' => $quantity,
                'currency' => $item['currency'],
                'unit_price_minor' => (int) $item['price_minor'],
                'line_total_minor' => (int) $item['price_minor'] * $quantity,
            ];
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function removeItem(
        string $customerId,
        string $itemId
    ): bool {
        $result = $this->db->query(
            <<<'SQL'
            DELETE FROM public.cart_items ci
            USING public.carts c
            WHERE ci.cart_id = c.id
              AND ci.id = ?
              AND c.customer_id = ?
              AND c.status = 'active'
            RETURNING ci.id
            SQL,
            [$itemId, $customerId]
        );

        return (bool) $result->fetch();
    }
}
