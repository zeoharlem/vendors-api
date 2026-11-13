
BEGIN;

-- =========================================================
-- 1. Extend the existing disputes table
-- =========================================================

ALTER TABLE public.disputes
    ADD COLUMN IF NOT EXISTS resolution_outcome VARCHAR(30),
    ADD COLUMN IF NOT EXISTS previous_vendor_order_status VARCHAR(30),
    ADD COLUMN IF NOT EXISTS closed_at TIMESTAMPTZ;

-- Backfill the payable reference for existing disputes.
UPDATE public.disputes d
SET vendor_payable_id = vp.id
FROM public.vendor_payables vp
WHERE vp.vendor_order_id = d.vendor_order_id
  AND d.vendor_payable_id IS NULL;

-- Preserve existing statuses and add the two waiting states.
ALTER TABLE public.disputes
    DROP CONSTRAINT IF EXISTS disputes_status_check;

ALTER TABLE public.disputes
    ADD CONSTRAINT disputes_status_check
    CHECK (
        status IN (
            'open',
            'under_review',
            'awaiting_customer',
            'awaiting_vendor',
            'resolved_customer',
            'resolved_vendor',
            'rejected',
            'closed'
        )
    );

-- Restrict structured resolution outcomes.
ALTER TABLE public.disputes
    ADD CONSTRAINT disputes_resolution_outcome_check
    CHECK (
        resolution_outcome IS NULL
        OR resolution_outcome IN (
            'customer_favor',
            'vendor_favor',
            'partial_refund',
            'no_refund'
        )
    );

-- =========================================================
-- 2. Ensure one active dispute per vendor order
-- =========================================================

-- Fail clearly instead of silently losing existing dispute records.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.disputes
        WHERE status IN (
            'open',
            'under_review',
            'awaiting_customer',
            'awaiting_vendor'
        )
        GROUP BY vendor_order_id
        HAVING COUNT(*) > 1
    ) THEN
        RAISE EXCEPTION
            'Cannot create active dispute uniqueness index: '
            'some vendor orders already have multiple active disputes.';
    END IF;
END;
$$;

CREATE UNIQUE INDEX IF NOT EXISTS uq_disputes_one_active_per_vendor_order
    ON public.disputes (vendor_order_id)
    WHERE status IN (
        'open',
        'under_review',
        'awaiting_customer',
        'awaiting_vendor'
    );

-- =========================================================
-- 3. Create dispute evidence metadata table
-- =========================================================

CREATE TABLE IF NOT EXISTS public.dispute_evidence (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    dispute_id UUID NOT NULL,

    uploaded_by UUID NOT NULL,

    original_filename VARCHAR(255) NOT NULL,

    storage_key TEXT NOT NULL,

    mime_type VARCHAR(127) NOT NULL,

    file_size_bytes BIGINT NOT NULL,

    checksum_sha256 CHAR(64),

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT dispute_evidence_dispute_id_fkey
        FOREIGN KEY (dispute_id)
        REFERENCES public.disputes(id)
        ON DELETE RESTRICT,

    CONSTRAINT dispute_evidence_uploaded_by_fkey
        FOREIGN KEY (uploaded_by)
        REFERENCES public.users(id)
        ON DELETE RESTRICT,

    CONSTRAINT dispute_evidence_file_size_check
        CHECK (file_size_bytes > 0),

    CONSTRAINT dispute_evidence_storage_key_key
        UNIQUE (storage_key)
);

CREATE INDEX IF NOT EXISTS idx_dispute_evidence_dispute_created
    ON public.dispute_evidence (dispute_id, created_at DESC);

-- =========================================================
-- 4. Create immutable dispute history table
-- =========================================================

CREATE TABLE IF NOT EXISTS public.dispute_history (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    dispute_id UUID NOT NULL,

    actor_id UUID,

    event_type VARCHAR(50) NOT NULL,

    previous_status VARCHAR(30),

    new_status VARCHAR(30),

    note TEXT,

    metadata JSONB NOT NULL DEFAULT '{}'::JSONB,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT dispute_history_dispute_id_fkey
        FOREIGN KEY (dispute_id)
        REFERENCES public.disputes(id)
        ON DELETE RESTRICT,

    CONSTRAINT dispute_history_actor_id_fkey
        FOREIGN KEY (actor_id)
        REFERENCES public.users(id)
        ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_dispute_history_dispute_created
    ON public.dispute_history (dispute_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_dispute_history_actor_created
    ON public.dispute_history (actor_id, created_at DESC);

COMMIT;
