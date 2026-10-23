<?php

declare(strict_types=1);

namespace App\Shared\Database;

use PDO;
use RuntimeException;

final class PostgresPdoHelper
{
    public function __construct(private readonly PDO $pdo) {
    }

    /**
     * Insert a user and return the database-generated UUID and status.
     */
    public function insertUser(array $data): array
    {
        $sql = <<<'SQL'
            INSERT INTO users (
                email,
                password_hash,
                first_name,
                last_name,
                phone
            )
            VALUES (
                :email,
                :password_hash,
                :first_name,
                :last_name,
                :phone
            )
            RETURNING id, status
        SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'email' => $data['email'],
            'password_hash' => $data['password_hash'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'],
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        if ($user === false || empty($user['id'])) {
            throw new RuntimeException(
                'PostgreSQL did not return the generated user UUID.'
            );
        }

        return $user;
    }

    /**
     * Assign a role to a user using their actual UUID.
     */
    public function assignUserRole(string $userId, int $roleId): void
    {
        $sql = <<<'SQL'
            INSERT INTO user_roles (user_id, role_id)
            VALUES (:user_id, :role_id)
        SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }
}
