
ALTER TABLE public.email_deliveries
    ADD COLUMN IF NOT EXISTS processing_at TIMESTAMPTZ NULL;

CREATE INDEX IF NOT EXISTS idx_email_deliveries_processing
    ON public.email_deliveries (processing_at)
    WHERE status = 'processing';
