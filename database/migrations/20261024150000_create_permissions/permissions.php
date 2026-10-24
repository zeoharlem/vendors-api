<?php

declare(strict_types=1);

use Phalcon\Migrations\Mvc\Model\Migration;

class PermissionsMigration_20261024150000 extends Migration
{
    public function up(): void
    {
        $this->getConnection()->execute(<<<'SQL'
        CREATE TABLE public.permissions (
            id SMALLSERIAL PRIMARY KEY,
            code VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(255),
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    SQL);
    }

    public function down(): void
    {
        $this->getConnection()->dropTable(
            'permissions',
            'public',
            true
        );
    }
}
