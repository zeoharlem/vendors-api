<?php

declare(strict_types=1);

use Phalcon\Migrations\Mvc\Model\Migration;

final class RefreshTokensMigration_20261023235734 extends Migration
{
    public function morph(): void
    {
        // Schema changes are handled by the explicit SQL in up().
    }

    public function up(): void
    {
        $statements = [
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS refresh_tokens (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                family_id UUID NOT NULL DEFAULT gen_random_uuid(),
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMPTZ NOT NULL,
                consumed_at TIMESTAMPTZ NULL,
                revoked_at TIMESTAMPTZ NULL,
                replaced_by_id UUID NULL REFERENCES refresh_tokens(id),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_refresh_tokens_user ON refresh_tokens(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_refresh_tokens_family ON refresh_tokens(family_id)',
            'CREATE INDEX IF NOT EXISTS idx_refresh_tokens_expiry ON refresh_tokens(expires_at)',
        ];

        foreach ($statements as $statement) {
            self::$connection->execute($statement);
        }
    }

    public function down(): void
    {
        self::$connection->execute(
            'DROP TABLE IF EXISTS refresh_tokens'
        );
    }
}
