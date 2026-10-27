<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation;

use Phalcon\Mvc\Router\Group;

final class AuthRoutes
{
    public static function register(Group $group): void
    {

        $namespace = 'App\\Modules\\Auth\\Presentation';


        $group->addGet('/auth', [
            'namespace' => $namespace,
            'controller' => 'Auth',
            'action' => 'index',
        ])->setName('v1.auth');

        $group->addPost('/auth/login', [
            'namespace' => $namespace,
            'controller' => 'Auth',
            'action' => 'login',
        ])->setName('v1.auth.login');

        $group->addPost('/auth/register', [
            'namespace' => $namespace,
            'controller' => 'Auth',
            'action' => 'register',
        ])->setName('v1.auth.register');

        $group->addPost('/auth/refresh', [
            'namespace' => $namespace,
            'controller' => 'Auth',
            'action' => 'refresh',
        ])->setName('v1.auth.refresh');

        $group->addPost('/auth/logout', [
            'namespace' => $namespace,
            'controller' => 'Auth',
            'action' => 'logout',
        ])->setName('v1.auth.logout');

    }
}