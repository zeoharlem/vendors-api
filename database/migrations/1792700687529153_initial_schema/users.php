<?php

declare(strict_types=1);

use Phalcon\Migrations\Mvc\Model\Migration;

final class UsersMigration_1792700687529153 extends Migration
{
    public function morph(): void
    {
        // Schema changes are handled by the explicit SQL in up().
    }

    public function up(): void
    {
        $statements = [
            <<<'SQL'
            CREATE TABLE users (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                email VARCHAR(254) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                phone VARCHAR(30),
                status VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'active', 'suspended', 'disabled')),
                email_verified_at TIMESTAMPTZ,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL,
            'CREATE UNIQUE INDEX users_email_unique ON users (LOWER(email))',
            <<<'SQL'
            CREATE TABLE roles (
                id SMALLINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                name VARCHAR(50) NOT NULL UNIQUE,
                description VARCHAR(255),
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL,
            <<<'SQL'
            CREATE TABLE user_roles (
                user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                role_id SMALLINT NOT NULL REFERENCES roles(id) ON DELETE RESTRICT,
                assigned_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, role_id)
            )
            SQL,
            <<<'SQL'
            CREATE TABLE vendor_profiles (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id UUID NOT NULL UNIQUE
                    REFERENCES users(id) ON DELETE RESTRICT,
                business_name VARCHAR(200) NOT NULL,
                business_registration_number VARCHAR(100),
                verification_status VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (verification_status IN ('pending', 'verified', 'rejected')),
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL,
            <<<'SQL'
            CREATE TABLE stores (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                vendor_profile_id UUID NOT NULL
                    REFERENCES vendor_profiles(id) ON DELETE RESTRICT,
                name VARCHAR(200) NOT NULL,
                slug VARCHAR(220) NOT NULL UNIQUE,
                description TEXT,
                status VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending', 'active', 'suspended', 'closed')),
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL,
            <<<'SQL'
            INSERT INTO roles (name, description) VALUES
                ('customer', 'Can purchase products'),
                ('vendor', 'Can manage vendor business and stores'),
                ('admin', 'Can administer the marketplace'),
                ('support', 'Can assist customers and vendors')
            SQL,
        ];

        foreach ($statements as $statement) {
            self::$connection->execute($statement);
        }
    }

    public function down(): void
    {
        $statements = [
            'DROP TABLE IF EXISTS stores CASCADE',
            'DROP TABLE IF EXISTS vendor_profiles CASCADE',
            'DROP TABLE IF EXISTS user_roles CASCADE',
            'DROP TABLE IF EXISTS roles CASCADE',
            'DROP TABLE IF EXISTS users CASCADE',
        ];

        foreach ($statements as $statement) {
            self::$connection->execute($statement);
        }
    }
}
