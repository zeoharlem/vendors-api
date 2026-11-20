
BEGIN;

-- Allow the new restoration entry type.
ALTER TABLE public.vendor_debt_entries
DROP CONSTRAINT vendor_debt_entries_type_check;

ALTER TABLE public.vendor_debt_entries
ADD CONSTRAINT vendor_debt_entries_type_check
CHECK (
    entry_type IN (
        'refund_debt_created',
        'payout_debt_reserved',
        'payout_debt_recovered',
        'payout_debt_released',
        'payout_debt_recovery_restored'
    )
);

-- Ensure payout-related entries always reference a payout,
-- while refund debt entries reference a refund.
ALTER TABLE public.vendor_debt_entries
DROP CONSTRAINT vendor_debt_entries_source_check;

ALTER TABLE public.vendor_debt_entries
ADD CONSTRAINT vendor_debt_entries_source_check
CHECK (
    (
        entry_type = 'refund_debt_created'
        AND refund_id IS NOT NULL
        AND payout_id IS NULL
    )
    OR
    (
        entry_type IN (
            'payout_debt_reserved',
            'payout_debt_recovered',
            'payout_debt_released',
            'payout_debt_recovery_restored'
        )
        AND payout_id IS NOT NULL
        AND refund_id IS NULL
    )
);

COMMIT;
