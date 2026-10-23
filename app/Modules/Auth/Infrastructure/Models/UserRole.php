<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Models;

use Phalcon\Mvc\Model;

final class UserRole extends Model
{
    public function initialize(): void
    {
        $this->setSource('user_roles');

        $this->belongsTo(
            'user_id',
            User::class,
            'id',
            [
                'alias' => 'user',
            ],
        );

        $this->belongsTo(
            'role_id',
            Role::class,
            'id',
            [
                'alias' => 'role',
            ],
        );
    }
}
