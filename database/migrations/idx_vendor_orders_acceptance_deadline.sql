BEGIN;

ALTER TABLE public.vendor_orders
    ADD COLUMN IF NOT EXISTS customer_confirmed_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS acceptance_deadline TIMESTAMPTZ NULL;

CREATE INDEX IF NOT EXISTS idx_vendor_orders_acceptance_deadline
    ON public.vendor_orders (acceptance_deadline)
    WHERE status = 'delivered'
      AND acceptance_deadline IS NOT NULL;

COMMIT;