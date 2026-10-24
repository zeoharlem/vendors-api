<?php

use Phalcon\Db\Column;
use Phalcon\Db\Exception;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Migrations\Mvc\Model\Migration;

/**
 * Class RefreshTokensMigration_1792851575673197
 */
class RefreshTokensMigration_1792851575673197 extends Migration
{
    /**
     * Define the table structure
     *
     * @return void
     * @throws Exception
     */
    public function morph(): void
    {
        $this->morphTable('refresh_tokens', [
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
                    'family_id',
                    [
                        'type' => Column::TYPE_UUID,
                        'default' => "gen_random_uuid()",
                        'notNull' => true,
                        'after' => 'user_id'
                    ]
                ),
                new Column(
                    'token_hash',
                    [
                        'type' => Column::TYPE_VARCHAR,
                        'notNull' => true,
                        'size' => 64,
                        'after' => 'family_id'
                    ]
                ),
                new Column(
                    'expires_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'notNull' => true,
                        'after' => 'token_hash'
                    ]
                ),
                new Column(
                    'consumed_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'notNull' => false,
                        'after' => 'expires_at'
                    ]
                ),
                new Column(
                    'revoked_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'notNull' => false,
                        'after' => 'consumed_at'
                    ]
                ),
                new Column(
                    'replaced_by_id',
                    [
                        'type' => Column::TYPE_UUID,
                        'notNull' => false,
                        'after' => 'revoked_at'
                    ]
                ),
                new Column(
                    'created_at',
                    [
                        'type' => Column::TYPE_TIMESTAMP,
                        'default' => "now()",
                        'notNull' => true,
                        'after' => 'replaced_by_id'
                    ]
                ),
            ],
            'indexes' => [
                new Index('idx_refresh_tokens_expiry', ['expires_at'], ''),
                new Index('idx_refresh_tokens_family', ['family_id'], ''),
                new Index('idx_refresh_tokens_user', ['user_id'], ''),
                new Index('refresh_tokens_token_hash_key', ['token_hash'], 'UNIQUE'),
            ],
            'references' => [
                new Reference(
                    'refresh_tokens_replaced_by_id_fkey',
                    [
                        'referencedSchema' => 'public',
                        'referencedTable' => 'refresh_tokens',
                        'columns' => ['replaced_by_id'],
                        'referencedColumns' => ['id'],
                        'onUpdate' => 'NO ACTION',
                        'onDelete' => 'NO ACTION'
                    ]
                ),
                new Reference(
                    'refresh_tokens_user_id_fkey',
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
