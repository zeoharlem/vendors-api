<?php

use Phalcon\Db\Column;
use Phalcon\Db\Exception;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Migrations\Mvc\Model\Migration;

/**
 * Class UsersMigration_1792851575673197
 */
class UsersMigration_1792851575673197 extends Migration
{
    /**
     * Define the table structure
     *
     * @return void
     * @throws Exception
     */
    public function morph(): void
    {
        $this->morphTable('users', [
            'columns' => [
                new Column(
                    'id',
                    [
                        'type' => Column::TYPE_UUID,
                        'default' => "gen_random_uuid()",
                        'primary' => true,
                        'notNull' => true,
                        'first' => true
                    ]
                ),
                new Column(
                    'email',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 254,
                        'after' => 'id'
                    ]
                ),
                new Column(
                    'password_hash',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 255,
                        'after' => 'email'
                    ]
                ),
                new Column(
                    'first_name',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 100,
                        'after' => 'password_hash'
                    ]
                ),
                new Column(
                    'last_name',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 100,
                        'after' => 'first_name'
                    ]
                ),
                new Column(
                    'phone',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => false,
                        'size' => 30,
                        'after' => 'last_name'
                    ]
                ),
                new Column(
                    'status',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'default' => "pending",
                        'notNull' => true,
                        'size' => 20,
                        'after' => 'phone'
                    ]
                ),
                new Column(
                    'email_verified_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'notNull' => false,
                        'after' => 'status'
                    ]
                ),
                new Column(
                    'created_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'default' => "CURRENT_TIMESTAMP",
                        'notNull' => true,
                        'after' => 'email_verified_at'
                    ]
                ),
                new Column(
                    'updated_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'default' => "CURRENT_TIMESTAMP",
                        'notNull' => true,
                        'after' => 'created_at'
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
