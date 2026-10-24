<?php

declare(strict_types=1);

namespace App\Shared\Security;

use InvalidArgumentException;
use Phalcon\Acl\Adapter\Memory;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use Phalcon\Db\Enum;

final class AuthorizationService
{
    public function __construct(
        private readonly AdapterInterface $db,
        private readonly Memory $acl,
    ) {
    }

    public function isAllowed(string $userId, string $resource, string $action): bool
    {
        foreach ($this->getRolesForUser($userId) as $role) {
            if ($this->acl->isAllowed($role, $resource, $action)) {
                return true;
            }
        }

        return false;
    }

    public function getRolesForUser(string $userId): array
    {
        $this->assertValidUserId($userId);

        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT DISTINCT r.name
                FROM public.user_roles ur
                INNER JOIN public.roles r
                    ON r.id = ur.role_id
                WHERE ur.user_id = :userId
                ORDER BY r.name
                SQL,
            Enum::FETCH_ASSOC,
            ['userId' => $userId],
        );

        return array_map(
            static fn(array $row): string => (string) $row['name'],
            $rows,
        );
    }

    public function userHasRole(
        string $userId,
        string $requiredRole,
    ): bool {
        return in_array(
            $requiredRole,
            $this->getRolesForUser($userId),
            true,
        );
    }

    private function assertValidUserId(string $userId): void
    {
        if (
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $userId,
            )
        ) {
            throw new InvalidArgumentException('Invalid user UUID.');
        }
    }
}