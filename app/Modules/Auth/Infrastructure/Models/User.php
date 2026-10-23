<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Models;

use Phalcon\Mvc\Model;

final class User extends Model
{
    //public $id, $email, $password, $first_name, $last_name, $phone;
    public $id;
    public $email;
    public $password_hash;
    public $first_name;
    public $last_name;
    public $phone;
    public $status;
    
    public function initialize(): void
    {
        $this->setSource('users');
        $this->skipAttributesOnCreate(['id']);

        $this->hasMany(
            'id',
            UserRole::class,
            'user_id',
            [
                'alias' => 'userRoles',
                'reusable' => true,
            ],
        );
    }
}
