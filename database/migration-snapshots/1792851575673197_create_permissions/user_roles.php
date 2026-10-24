<?php

use Phalcon\Db\Column;
use Phalcon\Db\Exception;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Migrations\Mvc\Model\Migration;

/**
 * Class UserRolesMigration_1792851575673197
 */
class UserRolesMigration_1792851575673197 extends Migration
{
    /**
     * Define the table structure
     *
     * @return void
     * @throws Exception
     */
    public function morph(): void
    {
        $this->morphTable('user_roles', [
            'columns' => [
                new Column(
                    'user_id',
                    [
                        'type' => Column::TYPE_UUID,
                        'primary' => true,
                        'notNull' => true,
                        'first' => true
                    ]
                ),
                new Column(
                    'role_id',
                    [
                        'type' => Column::TYPE_SMALLINTEGER,
                        'primary' => true,
                        'notNull' => true,
                        'size' => 1,
                        'after' => 'user_id'
                    ]
                ),
                new Column(
                    'assigned_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'default' => "CURRENT_TIMESTAMP",
                        'notNull' => true,
                        'after' => 'role_id'
                    ]
                ),
            ],
            'indexes' => [
                new Index('user_roles_pkey', ['user_id'], 'PRIMARY KEY'),
            ],
            'references' => [
                new Reference(
                    'user_roles_role_id_fkey',
                    [
                        'referencedSchema' => 'public',
                        'referencedTable' => 'roles',
                        'columns' => ['role_id'],
                        'referencedColumns' => ['id'],
                        'onUpdate' => 'NO ACTION',
                        'onDelete' => 'RESTRICT'
                    ]
                ),
                new Reference(
                    'user_roles_user_id_fkey',
                    [
                        'referencedSchema' => 'public',
                        'referencedTable' => 'users',
                        'columns' => ['user_id'],
                        'referencedColumns' => ['id'],
                        'onUpdate' => 'NO ACTION',
                        'onDelete' => 'CASCADE'
                    ]
                ),
            ],
            'options' => [
                'TABLE_COMMENT' => '',
            ],
        ]);
    }

    /**
     * Run the migrations
     *
     * @return void
     */
    public function up(): void
    {
    }

    /**
     * Reverse the migrations
     *
     * @return void
     */
    public function down(): void
    {
    }
}
