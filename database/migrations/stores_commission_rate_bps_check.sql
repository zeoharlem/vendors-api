BEGIN;

ALTER TABLE public.stores
ADD COLUMN commission_rate_bps SMALLINT NULL;

ALTER TABLE public.stores
ADD CONSTRAINT stores_commission_rate_bps_check
CHECK (
    commission_rate_bps IS NULL
    OR commission_rate_bps BETWEEN 0 AND 10000
);

COMMENT ON COLUMN public.stores.commission_rate_bps IS
'Optional store commission override in basis points. NULL uses the platform default.';

COMMIT;