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
use App\Modules\Product\Presentation\CategoryRoutes;
use App\Modules\Product\Presentation\AdminCategoryRoutes;
use App\Modules\Product\Presentation\VendorProductRoutes;
use App\Modules\Cart\Presentation\Routes\CartRoutes;
use App\Modules\Order\Presentation\Routes\OrderRoute;

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
        CategoryRoutes::register($api);
        AdminCategoryRoutes::register($api);
        VendorProductRoutes::register($api);
        CartRoutes::register($api);
        OrderRoute::register($api);

        $router->mount($api);
    }
}