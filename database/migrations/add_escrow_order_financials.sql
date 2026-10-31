
BEGIN;

-- 1. Delivery and acceptance-window tracking.
ALTER TABLE public.vendor_orders
    ADD COLUMN IF NOT EXISTS delivered_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS acceptance_deadline TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS payout_hold_reason TEXT;

CREATE INDEX IF NOT EXISTS idx_vendor_orders_acceptance_deadline
    ON public.vendor_orders (acceptance_deadline)
    WHERE delivered_at IS NOT NULL
      AND accepted_at IS NULL;

-- 2. Provider webhook/event idempotency.
CREATE TABLE IF NOT EXISTS public.payment_events (
    id UUID PRIMARY KEY,
    provider VARCHAR(40) NOT NULL,
    provider_event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    payment_reference VARCHAR(255),
    payload JSONB NOT NULL,
    processing_status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (processing_status IN ('pending', 'processing', 'processed', 'failed')),
    received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    processed_at TIMESTAMPTZ,
    processing_error TEXT,
    UNIQUE (provider, provider_event_id)
);

-- 3. Per-vendor-order amount held and amount payable.
CREATE TABLE IF NOT EXISTS public.vendor_payables (
    id UUID PRIMARY KEY,
    vendor_order_id UUID NOT NULL UNIQUE
        REFERENCES public.vendor_orders(id),
    currency CHAR(3) NOT NULL,
    gross_amount_minor BIGINT NOT NULL CHECK (gross_amount_minor >= 0),
    commission_amount_minor BIGINT NOT NULL DEFAULT 0
        CHECK (commission_amount_minor >= 0),
    payment_fee_amount_minor BIGINT NOT NULL DEFAULT 0
        CHECK (payment_fee_amount_minor >= 0),
    refund_amount_minor BIGINT NOT NULL DEFAULT 0
        CHECK (refund_amount_minor >= 0),
    net_payable_minor BIGINT NOT NULL CHECK (net_payable_minor >= 0),
    paid_out_minor BIGINT NOT NULL DEFAULT 0 CHECK (paid_out_minor >= 0),
    commission_rate_bps INTEGER,
    status VARCHAR(24) NOT NULL DEFAULT 'held'
        CHECK (status IN (
            'held', 'eligible', 'partially_paid', 'paid',
            'disputed', 'refunded', 'cancelled'
        )),
    release_eligible_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (paid_out_minor <= net_payable_minor),
    CHECK (refund_amount_minor <= gross_amount_minor)
);

-- 4. Payout attempts and provider reconciliation.
CREATE TABLE IF NOT EXISTS public.payouts (
    id UUID PRIMARY KEY,
    vendor_payable_id UUID NOT NULL
        REFERENCES public.vendor_payables(id),
    provider VARCHAR(40) NOT NULL,
    provider_reference VARCHAR(255),
    idempotency_key VARCHAR(255) NOT NULL UNIQUE,
    amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
    currency CHAR(3) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'pending'
        CHECK (status IN (
            'pending', 'processing', 'succeeded',
            'failed', 'reversed', 'cancelled'
        )),
    initiated_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    failure_reason TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_payout_provider_reference
    ON public.payouts (provider, provider_reference)
    WHERE provider_reference IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_payouts_reconciliation
    ON public.payouts (status, created_at);

-- 5. Refund records. Multiple partial refunds may be supported.
CREATE TABLE IF NOT EXISTS public.refunds (
    id UUID PRIMARY KEY,
    order_id UUID NOT NULL REFERENCES public.orders(id),
    vendor_order_id UUID REFERENCES public.vendor_orders(id),
    payment_id UUID NOT NULL REFERENCES public.payments(id),
    provider VARCHAR(40) NOT NULL,
    provider_reference VARCHAR(255),
    idempotency_key VARCHAR(255) NOT NULL UNIQUE,
    amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
    currency CHAR(3) NOT NULL,
    reason TEXT,
    status VARCHAR(24) NOT NULL DEFAULT 'pending'
        CHECK (status IN (
            'pending', 'processing', 'succeeded', 'failed', 'cancelled'
        )),
    requested_by UUID REFERENCES public.users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_refund_provider_reference
    ON public.refunds (provider, provider_reference)
    WHERE provider_reference IS NOT NULL;

-- 6. Disputes suspend payout eligibility.
CREATE TABLE IF NOT EXISTS public.disputes (
    id UUID PRIMARY KEY,
    order_id UUID NOT NULL REFERENCES public.orders(id),
    vendor_order_id UUID REFERENCES public.vendor_orders(id),
    opened_by UUID REFERENCES public.users(id),
    reason TEXT NOT NULL,
    description TEXT,
    status VARCHAR(24) NOT NULL DEFAULT 'open'
        CHECK (status IN (
            'open', 'investigating', 'resolved_customer',
            'resolved_vendor', 'closed'
        )),
    resolution TEXT,
    resolved_by UUID REFERENCES public.users(id),
    opened_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_disputes_active_vendor_order
    ON public.disputes (vendor_order_id, status);

-- 7. Financial journal header.
CREATE TABLE IF NOT EXISTS public.ledger_transactions (
    id UUID PRIMARY KEY,
    order_id UUID REFERENCES public.orders(id),
    vendor_order_id UUID REFERENCES public.vendor_orders(id),
    payment_id UUID REFERENCES public.payments(id),
    payout_id UUID REFERENCES public.payouts(id),
    refund_id UUID REFERENCES public.refunds(id),
    transaction_type VARCHAR(40) NOT NULL,
    reference VARCHAR(255) NOT NULL UNIQUE,
    currency CHAR(3) NOT NULL,
    description TEXT,
    metadata JSONB NOT NULL DEFAULT '{}'::JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- 8. Double-entry ledger accounts.
CREATE TABLE IF NOT EXISTS public.ledger_accounts (
    id UUID PRIMARY KEY,
    account_code VARCHAR(80) NOT NULL UNIQUE,
    account_name VARCHAR(160) NOT NULL,
    account_type VARCHAR(24) NOT NULL
        CHECK (account_type IN (
            'asset', 'liability', 'equity', 'revenue', 'expense'
        )),
    currency CHAR(3) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (account_code, currency)
);

CREATE TABLE IF NOT EXISTS public.ledger_entries (
    id UUID PRIMARY KEY,
    transaction_id UUID NOT NULL
        REFERENCES public.ledger_transactions(id),
    account_id UUID NOT NULL REFERENCES public.ledger_accounts(id),
    entry_side CHAR(1) NOT NULL CHECK (entry_side IN ('D', 'C')),
    amount_minor BIGINT NOT NULL CHECK (amount_minor > 0),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ledger_entries_transaction
    ON public.ledger_entries (transaction_id);

CREATE INDEX IF NOT EXISTS idx_ledger_entries_account
    ON public.ledger_entries (account_id, created_at);

COMMIT;
