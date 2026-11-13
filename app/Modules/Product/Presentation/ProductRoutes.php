<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use Phalcon\Mvc\Router\Group;

final class ProductRoutes
{
    public static function register(Group $api): void
    {
        $namespace = 'App\\Modules\\Product\\Presentation';

        $api->addGet('/health', [
            'namespace' => $namespace,
            'controller' => 'Health',
            'action' => 'index',
        ])->setName('v1.health');

        $api->addPost('/products', [
            'namespace' => $namespace,
            'controller' => 'Product',
            'action' => 'create',
        ]);

        $api->addGet('/products/{id}', [
            'namespace' => $namespace,
            'controller' => 'Product',
            'action' => 'show',
        ]);

        $api->addPut('/products/{id}', [
            'namespace' => $namespace,
            'controller' => 'Product',
            'action' => 'update',
        ]);

        $api->addPatch('/products/{id}', [
            'namespace' => $namespace,
            'controller' => 'Product',
            'action' => 'update',
        ]);

        $api->addDelete('/products/{id}', [
            'namespace' => $namespace,
            'controller' => 'Product',
            'action' => 'archive',
        ]);

        $api->addGet('/vendor/products/{id}', [
            'namespace' => $namespace,
            'controller' => 'Product',
            'action' => 'manageShow',
        ]);

        $api->addPost('/admin/products/{id}/publish', [
            'namespace' => $namespace,
            'controller' => 'Product',
            'action' => 'publish',
        ]);

        $api->addPost('/products/{id}/images', [
            'namespace' => $namespace,
            'controller' => 'ProductImage',
            'action' => 'upload',
        ]);

        $api->addGet('/products/{id}/images', [
            'namespace' => $namespace,
            'controller' => 'ProductImage',
            'action' => 'index',
        ]);

        $api->addPatch('/products/{id}/images/{imageId}', [
            'namespace' => $namespace,
            'controller' => 'ProductImage',
            'action' => 'update',
        ]);

        $api->addDelete('/products/{id}/images/{imageId}', [
            'namespace' => $namespace,
            'controller' => 'ProductImage',
            'action' => 'delete',
        ]);

        $api->addGet('/products/{id}/images/{imageId}/file', [
            'namespace' => $namespace,
            'controller' => 'ProductImage',
            'action' => 'file',
        ]);

        $api->addPost('/products/{id}/images/{imageId}/replace', [
            'namespace' => $namespace,
            'controller' => 'ProductImage',
            'action' => 'replace',
        ]);
    }
}
