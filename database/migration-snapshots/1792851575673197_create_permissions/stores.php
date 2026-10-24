<?php

use Phalcon\Db\Column;
use Phalcon\Db\Exception;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Migrations\Mvc\Model\Migration;

/**
 * Class StoresMigration_1792851575673197
 */
class StoresMigration_1792851575673197 extends Migration
{
    /**
     * Define the table structure
     *
     * @return void
     * @throws Exception
     */
    public function morph(): void
    {
        $this->morphTable('stores', [
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
                    'vendor_profile_id',
                    [
                        'type' => Column::TYPE_UUID,
                        'notNull' => true,
                        'after' => 'id'
                    ]
                ),
                new Column(
                    'name',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 200,
                        'after' => 'vendor_profile_id'
                    ]
                ),
                new Column(
                    'slug',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 220,
                        'after' => 'name'
                    ]
                ),
                new Column(
                    'description',
                    [
                        'type' => Column::TYPE_TEXT,
                        'notNull' => false,
                        'after' => 'slug'
                    ]
                ),
                new Column(
                    'status',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'default' => "pending",
                        'notNull' => true,
                        'size' => 20,
                        'after' => 'description'
                    ]
                ),
                new Column(
                    'created_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'default' => "CURRENT_TIMESTAMP",
                        'notNull' => true,
                        'after' => 'status'
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
                new Index('stores_slug_key', ['slug'], 'UNIQUE'),
            ],
            'references' => [
                new Reference(
                    'stores_vendor_profile_id_fkey',
                    [
                        'referencedSchema' => 'public',
                        'referencedTable' => 'vendor_profiles',
                        'columns' => ['vendor_profile_id'],
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
