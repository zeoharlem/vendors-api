<?php
namespace App\Bootstrap\Routes;

use App\Modules\Auth\Presentation\AuthRoutes;
use App\Modules\Product\Presentation\ProductRoutes;
use Phalcon\Mvc\Router;
use Phalcon\Mvc\Router\Group;

final class ApiRoutes {
    
    public static function register(Router $router): void {
        $api = new Group();

        $api->setPrefix('/api/v1');

        AuthRoutes::register($api);
        ProductRoutes::register($api);

        $router->mount($api);
    }
}