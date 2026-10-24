<?php

use Phalcon\Db\Column;
use Phalcon\Db\Exception;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Migrations\Mvc\Model\Migration;

/**
 * Class RolesMigration_1792851575673197
 */
class RolesMigration_1792851575673197 extends Migration
{
    /**
     * Define the table structure
     *
     * @return void
     * @throws Exception
     */
    public function morph(): void
    {
        $this->morphTable('roles', [
            'columns' => [
                new Column(
                    'id',
                    [
                        'type' => Column::TYPE_SMALLINTEGER,
                        'primary' => true,
                        'notNull' => true,
                        'size' => 1,
                        'first' => true
                    ]
                ),
                new Column(
                    'name',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 50,
                        'after' => 'id'
                    ]
                ),
                new Column(
                    'description',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => false,
                        'size' => 255,
                        'after' => 'name'
                    ]
                ),
                new Column(
                    'created_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'default' => "CURRENT_TIMESTAMP",
                        'notNull' => true,
                        'after' => 'description'
                    ]
                ),
            ],
            'indexes' => [
                new Index('roles_name_key', ['name'], 'UNIQUE'),
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
        $this->getConnection()->execute(<<<'SQL'
        CREATE TABLE public.permissions (
            id SMALLSERIAL PRIMARY KEY,
            code VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(255),
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    SQL);
    }

    /**
     * Reverse the migrations
     *
     * @return void
     */
    public function down(): void
    {
        $this->getConnection()->dropTable('permissions', 'public', true);
    }
}
