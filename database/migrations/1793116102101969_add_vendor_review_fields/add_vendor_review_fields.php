<?php

declare(strict_types=1);

use Phalcon\Migrations\Mvc\Model\Migration;

final class AddVendorReviewFieldsMigration_1793116102101969 extends Migration
{
    public function morph(): void
    {
        // No model morphing required.
    }

    public function up(): void
    {
        $statements = [
            <<<'SQL'
            ALTER TABLE public.vendor_profiles
                ADD COLUMN IF NOT EXISTS reviewed_by UUID NULL
                    REFERENCES public.users(id) ON DELETE SET NULL,
                ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMPTZ NULL,
                ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL
            SQL,
        ];

        foreach ($statements as $statement) {
            self::$connection->execute($statement);
        }
    }

    public function down(): void
    {
        self::$connection->execute(
            <<<'SQL'
            ALTER TABLE public.vendor_profiles
                DROP COLUMN IF EXISTS rejection_reason,
                DROP COLUMN IF EXISTS reviewed_at,
                DROP COLUMN IF EXISTS reviewed_by
            SQL
        );
    }
}