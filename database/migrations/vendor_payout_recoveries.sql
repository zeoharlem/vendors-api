CREATE TABLE public.vendor_payout_recoveries (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    payout_id UUID NOT NULL
        REFERENCES public.payouts(id),

    vendor_payable_id UUID NOT NULL
        REFERENCES public.vendor_payables(id),

    store_id UUID NOT NULL
        REFERENCES public.stores(id),

    amount_minor BIGINT NOT NULL
        CHECK (amount_minor > 0),

    currency CHAR(3) NOT NULL DEFAULT 'NGN',

    -- How the vendor returned the money.
    return_method VARCHAR(40) NOT NULL,

    -- Reference from the bank, transfer provider, or other payment channel.
    provider_reference VARCHAR(150),

    -- Administrator's verification reference or evidence identifier.
    confirmation_reference VARCHAR(150) NOT NULL,

    notes TEXT,

    status VARCHAR(20) NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'confirmed', 'rejected')),

    recorded_by UUID NOT NULL
        REFERENCES public.users(id),

    confirmed_by UUID
        REFERENCES public.users(id),

    confirmed_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT vendor_payout_recoveries_confirmation_check
        CHECK (
            (status = 'confirmed'
                AND confirmed_by IS NOT NULL
                AND confirmed_at IS NOT NULL)
            OR
            (status <> 'confirmed'
                AND confirmed_at IS NULL)
        )
);

CREATE INDEX idx_vendor_payout_recoveries_payout_status
    ON public.vendor_payout_recoveries (payout_id, status);

CREATE INDEX idx_vendor_payout_recoveries_payable_status
    ON public.vendor_payout_recoveries (vendor_payable_id, status);

CREATE INDEX idx_vendor_payout_recoveries_store_created
    ON public.vendor_payout_recoveries (store_id, created_at DESC);

CREATE UNIQUE INDEX uq_vendor_payout_recoveries_provider_reference
    ON public.vendor_payout_recoveries (provider_reference)
    WHERE provider_reference IS NOT NULL;