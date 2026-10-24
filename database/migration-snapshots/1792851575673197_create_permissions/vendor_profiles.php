<?php

use Phalcon\Db\Column;
use Phalcon\Db\Exception;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Migrations\Mvc\Model\Migration;

/**
 * Class VendorProfilesMigration_1792851575673197
 */
class VendorProfilesMigration_1792851575673197 extends Migration
{
    /**
     * Define the table structure
     *
     * @return void
     * @throws Exception
     */
    public function morph(): void
    {
        $this->morphTable('vendor_profiles', [
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
                    'user_id',
                    [
                        'type' => Column::TYPE_UUID,
                        'notNull' => true,
                        'after' => 'id'
                    ]
                ),
                new Column(
                    'business_name',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 200,
                        'after' => 'user_id'
                    ]
                ),
                new Column(
                    'business_registration_number',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => false,
                        'size' => 100,
                        'after' => 'business_name'
                    ]
                ),
                new Column(
                    'verification_status',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'default' => "pending",
                        'notNull' => true,
                        'size' => 20,
                        'after' => 'business_registration_number'
                    ]
                ),
                new Column(
                    'created_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'default' => "CURRENT_TIMESTAMP",
                        'notNull' => true,
                        'after' => 'verification_status'
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
            'indexes' => [
                new Index('vendor_profiles_user_id_key', ['user_id'], 'UNIQUE'),
            ],
            'references' => [
                new Reference(
                    'vendor_profiles_user_id_fkey',
                    [
                        'referencedSchema' => 'public',
                        'referencedTable' => 'users',
                        'columns' => ['user_id'],
                        'referencedColumns' => ['id'],
                        'onUpdate' => 'NO ACTION',
                        'onDelete' => 'RESTRICT'
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
