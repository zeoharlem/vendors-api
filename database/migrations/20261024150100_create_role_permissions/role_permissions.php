<?php

declare(strict_types=1);

use Phalcon\Migrations\Mvc\Model\Migration;

class RolePermissionsMigration_20261024150100 extends Migration
{
    public function up(): void
    {
        $this->getConnection()->execute(<<<'SQL'
            CREATE TABLE public.role_permissions (
                role_id SMALLINT NOT NULL,
                permission_id SMALLINT NOT NULL,
                assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                CONSTRAINT pk_role_permissions
                    PRIMARY KEY (role_id, permission_id),

                CONSTRAINT fk_role_permissions_role
                    FOREIGN KEY (role_id)
                    REFERENCES public.roles (id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_role_permissions_permission
                    FOREIGN KEY (permission_id)
                    REFERENCES public.permissions (id)
                    ON DELETE CASCADE
            )
        SQL);

        $this->getConnection()->execute(<<<'SQL'
            CREATE INDEX idx_role_permissions_permission_id
            ON public.role_permissions (permission_id)
        SQL);
    }

    public function down(): void
    {
        $this->getConnection()->dropTable(
            'role_permissions',
            'public',
            true
        );
    }
}
