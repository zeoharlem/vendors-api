<?php
namespace App\Bootstrap\Routes;

use App\Modules\Auth\Presentation\AuthRoutes;
use App\Modules\Notification\Presentation\NotificationRoutes;
use App\Modules\Product\Presentation\ProductRoutes;
use App\Modules\Store\Presentation\StoreRoutes;
use App\Modules\Vendor\Presentation\AdminVendorRoutes;
use App\Modules\Vendor\Presentation\VendorRoutes;
use Phalcon\Mvc\Router;
use Phalcon\Mvc\Router\Group;

final class ApiRoutes {
    
    public static function register(Router $router): void {
        $api = new Group();

        $api->setPrefix('/api/v1');

        AuthRoutes::register($api);
        ProductRoutes::register($api);
        VendorRoutes::register($api);
        StoreRoutes::register($api);
        NotificationRoutes::register($api);
        AdminVendorRoutes::register($api);

        $router->mount($api);
    }
}