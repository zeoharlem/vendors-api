<?php

declare(strict_types=1);

use Phalcon\Migrations\Mvc\Model\Migration;

final class NotificationMigration_1793082250318501 extends Migration
{
    public function morph(): void
    {
        // Schema changes are handled by the explicit SQL in up().
    }

    public function up(): void
    {
        $statements = [
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS public.notifications (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    user_id UUID NOT NULL
                        REFERENCES public.users(id) ON DELETE CASCADE,
                    type VARCHAR(100) NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    message TEXT NOT NULL,
                    data JSONB NOT NULL DEFAULT '{}'::jsonb,
                    read_at TIMESTAMPTZ NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            SQL,
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_notifications_user_created
                ON public.notifications (user_id, created_at DESC, id DESC)
            SQL,
            <<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_notifications_unread
                ON public.notifications (user_id, created_at DESC)
                WHERE read_at IS NULL
            SQL,
        ];

        foreach ($statements as $statement) {
            self::$connection->execute($statement);
        }
    }

    public function down(): void
    {
        self::$connection->execute(
            'DROP TABLE IF EXISTS public.notifications'
        );
    }
}