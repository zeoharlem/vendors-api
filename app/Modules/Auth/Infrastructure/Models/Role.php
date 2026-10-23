<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Models;

use Phalcon\Mvc\Model;

final class Role extends Model
{
    public function initialize(): void
    {
        $this->setSource('roles');

        $this->hasMany(
            'id',
            UserRole::class,
            'role_id',
            [
                'alias' => 'userRoles',
                'reusable' => true,
            ],
        );
    }
}
