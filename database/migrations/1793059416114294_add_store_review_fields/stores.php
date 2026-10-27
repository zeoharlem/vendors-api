<?php

declare(strict_types=1);

use Phalcon\Migrations\Mvc\Model\Migration;

final class StoresMigration_1793059416114294 extends Migration
{
    public function morph(): void
    {
        // Schema changes are handled by the explicit SQL in up().
    }

    public function up(): void
    {
        $statements = [
            <<<'SQL'
            ALTER TABLE public.stores
                DROP CONSTRAINT IF EXISTS stores_status_check
            SQL,

            <<<'SQL'
            ALTER TABLE public.stores
                ADD CONSTRAINT stores_status_check
                CHECK (
                    status IN (
                        'pending',
                        'active',
                        'rejected',
                        'suspended',
                        'closed'
                    )
                )
            SQL,

            <<<'SQL'
            ALTER TABLE public.stores
                ADD COLUMN IF NOT EXISTS reviewed_by UUID NULL,
                ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMPTZ NULL,
                ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL
            SQL,

            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM pg_constraint
                    WHERE conname = 'stores_reviewed_by_fkey'
                      AND conrelid = 'public.stores'::regclass
                ) THEN
                    ALTER TABLE public.stores
                        ADD CONSTRAINT stores_reviewed_by_fkey
                        FOREIGN KEY (reviewed_by)
                        REFERENCES public.users(id)
                        ON DELETE SET NULL;
                END IF;
            END
            $$
            SQL,
        ];

        foreach ($statements as $statement) {
            self::$connection->execute($statement);
        }
    }

    public function down(): void
    {
        $statements = [
            <<<'SQL'
            ALTER TABLE public.stores
                DROP CONSTRAINT IF EXISTS stores_reviewed_by_fkey
            SQL,

            <<<'SQL'
            ALTER TABLE public.stores
                DROP COLUMN IF EXISTS reviewed_by,
                DROP COLUMN IF EXISTS reviewed_at,
                DROP COLUMN IF EXISTS rejection_reason
            SQL,

            <<<'SQL'
            ALTER TABLE public.stores
                DROP CONSTRAINT IF EXISTS stores_status_check
            SQL,

            <<<'SQL'
            ALTER TABLE public.stores
                ADD CONSTRAINT stores_status_check
                CHECK (
                    status IN (
                        'pending',
                        'active',
                        'suspended',
                        'closed'
                    )
                )
            SQL,
        ];

        foreach ($statements as $statement) {
            self::$connection->execute($statement);
        }
    }
}
