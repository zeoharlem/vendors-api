
BEGIN;

-- =========================================================
-- 1. PROTECT LEDGER ENTRIES
-- =========================================================

CREATE OR REPLACE FUNCTION public.prevent_posted_ledger_entry_mutation()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    transaction_status VARCHAR(20);
    transaction_id UUID;
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.ledger_transaction_id IS DISTINCT FROM OLD.ledger_transaction_id THEN
            RAISE EXCEPTION
                'Ledger entries cannot be moved between transactions.';
        END IF;

        transaction_id := OLD.ledger_transaction_id;

    ELSIF TG_OP = 'DELETE' THEN
        transaction_id := OLD.ledger_transaction_id;

    ELSE
        transaction_id := NEW.ledger_transaction_id;
    END IF;

    SELECT status
    INTO transaction_status
    FROM public.ledger_transactions
    WHERE id = transaction_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION
            'Ledger transaction % does not exist.',
            transaction_id;
    END IF;

    IF transaction_status = 'posted' THEN
        RAISE EXCEPTION
            'Entries belonging to posted ledger transactions are immutable.';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;


DROP TRIGGER IF EXISTS trg_prevent_posted_ledger_entry_mutation
ON public.ledger_entries;

CREATE TRIGGER trg_prevent_posted_ledger_entry_mutation
BEFORE INSERT OR UPDATE OR DELETE
ON public.ledger_entries
FOR EACH ROW
EXECUTE FUNCTION public.prevent_posted_ledger_entry_mutation();


-- =========================================================
-- 2. PROTECT POSTED LEDGER TRANSACTIONS
-- =========================================================

CREATE OR REPLACE FUNCTION public.prevent_posted_ledger_transaction_mutation()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.status = 'posted' THEN

        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION
                'Posted ledger transactions cannot be deleted.';
        END IF;

        IF NEW IS DISTINCT FROM OLD THEN
            RAISE EXCEPTION
                'Posted ledger transactions are immutable.';
        END IF;

    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;


DROP TRIGGER IF EXISTS trg_prevent_posted_ledger_transaction_mutation
ON public.ledger_transactions;

CREATE TRIGGER trg_prevent_posted_ledger_transaction_mutation
BEFORE UPDATE OR DELETE
ON public.ledger_transactions
FOR EACH ROW
EXECUTE FUNCTION public.prevent_posted_ledger_transaction_mutation();


COMMIT;


-- =========================================================
-- 3. VERIFY CREATED TRIGGERS
-- =========================================================

SELECT
    trigger_name,
    event_manipulation,
    event_object_table,
    action_timing
FROM information_schema.triggers
WHERE trigger_schema = 'public'
  AND event_object_table IN (
      'ledger_entries',
      'ledger_transactions'
  )
ORDER BY
    event_object_table,
    trigger_name,
    event_manipulation;
