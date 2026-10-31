
BEGIN;

-- One parent order per customer checkout.
CREATE TABLE IF NOT EXISTS public.orders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_number VARCHAR(40) NOT NULL UNIQUE,
    customer_id UUID NOT NULL REFERENCES public.users(id),
    status VARCHAR(30) NOT NULL DEFAULT 'pending_payment'
        CHECK (status IN (
            'pending_payment',
            'paid',
            'processing',
            'partially_fulfilled',
            'fulfilled',
            'completed',
            'cancelled',
            'partially_refunded',
            'refunded'
        )),
    payment_status VARCHAR(30) NOT NULL DEFAULT 'pending'
        CHECK (payment_status IN (
            'pending',
            'processing',
            'paid',
            'failed',
            'cancelled',
            'partially_refunded',
            'refunded'
        )),
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    subtotal_minor BIGINT NOT NULL CHECK (subtotal_minor >= 0),
    shipping_minor BIGINT NOT NULL DEFAULT 0 CHECK (shipping_minor >= 0),
    tax_minor BIGINT NOT NULL DEFAULT 0 CHECK (tax_minor >= 0),
    discount_minor BIGINT NOT NULL DEFAULT 0 CHECK (discount_minor >= 0),
    total_minor BIGINT NOT NULL CHECK (total_minor >= 0),
    shipping_address JSONB NOT NULL,
    billing_address JSONB,
    customer_note TEXT,
    idempotency_key VARCHAR(128) NOT NULL,
    paid_at TIMESTAMPTZ,
    cancelled_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (customer_id, idempotency_key)
);

CREATE INDEX IF NOT EXISTS idx_orders_customer_created
    ON public.orders (customer_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_orders_status_created
    ON public.orders (status, created_at DESC);


-- A separate fulfilment order for each store in a checkout.
CREATE TABLE IF NOT EXISTS public.vendor_orders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL REFERENCES public.orders(id),
    store_id UUID NOT NULL REFERENCES public.stores(id),
    order_number VARCHAR(40) NOT NULL UNIQUE,
    status VARCHAR(30) NOT NULL DEFAULT 'pending'
        CHECK (status IN (
            'pending',
            'accepted',
            'processing',
            'ready_to_ship',
            'shipped',
            'delivered',
            'completed',
            'cancelled',
            'disputed',
            'partially_refunded',
            'refunded'
        )),
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    subtotal_minor BIGINT NOT NULL CHECK (subtotal_minor >= 0),
    shipping_minor BIGINT NOT NULL DEFAULT 0 CHECK (shipping_minor >= 0),
    tax_minor BIGINT NOT NULL DEFAULT 0 CHECK (tax_minor >= 0),
    discount_minor BIGINT NOT NULL DEFAULT 0 CHECK (discount_minor >= 0),
    total_minor BIGINT NOT NULL CHECK (total_minor >= 0),
    accepted_at TIMESTAMPTZ,
    shipped_at TIMESTAMPTZ,
    delivered_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    cancelled_at TIMESTAMPTZ,
    cancellation_reason TEXT,
    tracking_number VARCHAR(150),
    tracking_url TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (order_id, store_id)
);

CREATE INDEX IF NOT EXISTS idx_vendor_orders_store_status
    ON public.vendor_orders (store_id, status, created_at DESC);


-- Immutable purchase-time product and price snapshots.
CREATE TABLE IF NOT EXISTS public.order_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL REFERENCES public.orders(id),
    vendor_order_id UUID NOT NULL REFERENCES public.vendor_orders(id),
    product_variant_id UUID NOT NULL REFERENCES public.product_variants(id),
    product_name VARCHAR(255) NOT NULL,
    variant_name VARCHAR(255),
    sku VARCHAR(150) NOT NULL,
    attributes JSONB NOT NULL DEFAULT '{}'::jsonb,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    unit_price_minor BIGINT NOT NULL CHECK (unit_price_minor >= 0),
    discount_minor BIGINT NOT NULL DEFAULT 0 CHECK (discount_minor >= 0),
    tax_minor BIGINT NOT NULL DEFAULT 0 CHECK (tax_minor >= 0),
    line_total_minor BIGINT NOT NULL CHECK (line_total_minor >= 0),
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_order_items_order
    ON public.order_items (order_id);

CREATE INDEX IF NOT EXISTS idx_order_items_vendor_order
    ON public.order_items (vendor_order_id);


-- Payment attempts are separate from the order lifecycle.
CREATE TABLE IF NOT EXISTS public.payments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL REFERENCES public.orders(id),
    provider VARCHAR(30) NOT NULL DEFAULT 'paystack',
    reference VARCHAR(100) NOT NULL UNIQUE,
    amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    status VARCHAR(30) NOT NULL DEFAULT 'pending'
        CHECK (status IN (
            'pending',
            'initialized',
            'success',
            'failed',
            'abandoned',
            'refund_pending',
            'partially_refunded',
            'refunded'
        )),
    authorization_url TEXT,
    access_code VARCHAR(255),
    provider_transaction_id VARCHAR(150),
    failure_reason TEXT,
    verified_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_payments_order
    ON public.payments (order_id, created_at DESC);


-- Audit trail for order status transitions.
CREATE TABLE IF NOT EXISTS public.order_status_history (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL REFERENCES public.orders(id),
    vendor_order_id UUID REFERENCES public.vendor_orders(id),
    old_status VARCHAR(30),
    new_status VARCHAR(30) NOT NULL,
    changed_by UUID REFERENCES public.users(id),
    reason TEXT,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_order_history_order
    ON public.order_status_history (order_id, created_at DESC);

COMMIT;
