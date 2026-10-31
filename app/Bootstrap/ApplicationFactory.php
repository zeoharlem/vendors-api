<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Bootstrap\Routes\ApiRoutes;
use App\Modules\Product\Application\Services\ProductService;
use App\Modules\Product\Domain\Repositories\ProductCategoryRepository;
use App\Modules\Product\Domain\Repositories\ProductVariantRepository;
use App\Modules\Product\Domain\Repositories\InventoryRepository;
use App\Modules\Product\Domain\Repositories\InventoryMovementRepository;
use App\Modules\Product\Infrastructure\Persistence\PdoProductCategoryRepository;
use App\Modules\Product\Infrastructure\Persistence\PdoProductVariantRepository;
use App\Modules\Product\Infrastructure\Persistence\PdoInventoryRepository;
use App\Modules\Product\Infrastructure\Persistence\PdoInventoryMovementRepository;
use App\Modules\Auth\Application\Services\LoginUserService;
use App\Modules\Auth\Application\Services\RefreshTokenService;
use App\Modules\Auth\Application\Services\RegisterUserService;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\Repository\NotificationRepository;
use App\Modules\Notification\Infrastructure\Persistence\PdoNotificationRepository;
use App\Modules\Product\Infrastructure\Persistence\PdoProductRepository;
use App\Modules\Product\Infrastructure\Persistence\PdoStoreAccessChecker;
use App\Modules\Store\Application\Services\StoreReviewService;
use App\Modules\Vendor\Application\Services\VendorProfileService;
use App\Modules\Vendor\Domain\Repository\VendorRepository;
use App\Modules\Vendor\Infrastructure\Persistence\PdoVendorRepository;
use App\Shared\Http\JsonResponder;
use App\Shared\Security\AclFactory;
use App\Shared\Security\AuthorizationMiddleware;
use App\Shared\Security\AuthorizationService;
use Phalcon\Acl\Adapter\Memory as AclMemory;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;
use Phalcon\Mvc\Router;
use Phalcon\Db\Adapter\Pdo\Postgresql;
use Phalcon\Mvc\View;
use App\Shared\Security\JwtAuthMiddleware;
use App\Shared\Security\JwtService;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\Model\Transaction\Manager;
use App\Modules\Store\Application\Services\StoreService;
use App\Modules\Store\Domain\Repository\StoreRepository;
use App\Modules\Store\Infrastructure\Persistence\PdoStoreRepository;
use App\Modules\Vendor\Application\Services\VendorReviewService;
use App\Modules\Vendor\Presentation\Validators\RejectVendorValidator;
use App\Modules\Product\Application\Services\CategoryService;
use App\Modules\Product\Domain\Repositories\CategoryRepository;
use App\Modules\Product\Infrastructure\Persistence\PdoCategoryRepository;
use App\Modules\Cart\Application\Services\CartService;
use App\Modules\Cart\Domain\Repositories\CartRepository;
use App\Modules\Cart\Infrastructure\Persistence\PdoCartRepository;

final class ApplicationFactory
{
    public static function create(string $root): Application
    {
        $di = new FactoryDefault();

        //View Injection
        $di->setShared('view', function (): View {
            $view = new View();
            $view->disable();

            return $view;
        });

        //Config Injection
        $di->setShared('config', fn(): array => [
            'app' => [
                'name' => $_ENV['APP_NAME'] ?? 'Marketplace API',
                'env' => $_ENV['APP_ENV'] ?? 'production',
                'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
            ],
            'db' => [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => (int) ($_ENV['DB_PORT'] ?? 5432),
                'dbname' => $_ENV['DB_NAME'] ?? 'multivendors',
                'username' => $_ENV['DB_USERNAME'] ?? 'postgres',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ],
            'auth' => [
                'access_token_ttl' => (int) ($_ENV['JWT_TTL'] ?? 900),
                'refresh_token_ttl' => (int) ($_ENV['REFRESH_TOKEN_TTL'] ?? 2592000),
            ],
        ]);

        //Database Injection
        $di->setShared('db', function () use ($di): Postgresql {
            $config = $di->getShared('config')['db'];
            return new Postgresql([
                'host' => $config['host'],
                'port' => $config['port'],
                'dbname' => $config['dbname'],
                'username' => $config['username'],
                'password' => $config['password'],
            ]);
        });

        //JsonResponder for Payload
        $di->setShared('jsonResponder', fn(): JsonResponder => new JsonResponder());

        //JwtService Injection
        $di->setShared('jwtService', function (): JwtService {
            return new JwtService(
                (string) ($_ENV['JWT_SECRET'] ?? ''),
                (string) ($_ENV['JWT_ISSUER'] ?? 'vendors-api'),
                (string) ($_ENV['JWT_AUDIENCE'] ?? 'vendors-api-clients'),
                (int) ($_ENV['JWT_TTL'] ?? 900),
            );
        });

        $di->setShared(
            'acl',
            function (): AclMemory {
                return AclFactory::create();
            },
        );

        $di->setShared(
            'jwtAuthMiddleware',
            function () use ($di): JwtAuthMiddleware {
                return new JwtAuthMiddleware(
                    $di->getShared('jwtService'),
                    $di->getShared('request'),
                    $di->getShared('jsonResponder'),
                    $di->getShared('refreshTokenService'),
                );
            },
        );

        $di->setShared(
            'authorization',
            function () use ($di): AuthorizationService {
                return new AuthorizationService($di->getShared('db'), $di->getShared('acl'));
            },
        );

        $di->setShared(
            'authorizationMiddleware',
            function () use ($di): AuthorizationMiddleware {
                return new AuthorizationMiddleware($di->getShared('authorization'), $di->getShared('jsonResponder'));
            },
        );

        //Router Injection
        $di->setShared('router', function (): Router {
            $router = new Router(false);
            ApiRoutes::register($router);
            return $router;
        });

        //Transactions
        $di->setShared('transactions', fn(): Manager => new Manager($di));

        $di->setShared(
            'refreshTokenService',
            function () use ($di): RefreshTokenService {
                $db = $di->getShared('db');

                return new RefreshTokenService(
                    $db->getInternalHandler(),
                    $di->getShared('jwtService'),
                    (int) $di->getShared('config')['auth']['refresh_token_ttl'],
                );
            },
        );

        /**
         * Services registration
         * @example RegisterUserServices
         */
        $di->setShared(
            'registerUserService',
            fn(): RegisterUserService => new RegisterUserService($di->getShared('transactions')),
        );

        $di->setShared(
            'loginUserService',
            fn(): LoginUserService => new LoginUserService(
                $di->getShared('jwtService'),
                $di->getShared('refreshTokenService')
            ),
        );

        /**
         * Vendor Repository
         */
        $di->setShared(
            'vendorRepository',
            fn(): VendorRepository => new PdoVendorRepository($di->getShared('db'))
        );

        $di->setShared(
            'vendorProfileService',
            fn(): VendorProfileService => new VendorProfileService($di->getShared('vendorRepository'))
        );

        // NEW: Store repository
        $di->setShared('storeRepository', function () use ($di): StoreRepository {
            return new PdoStoreRepository($di->getShared('db'));
        });

        // NEW: Store service
        $di->setShared('storeService', function () use ($di): StoreService {
            return new StoreService(
                $di->getShared('storeRepository'),
                $di->getShared('vendorRepository')
            );
        });

        // NEW: Store review service
        $di->setShared('storeReviewService', function () use ($di): StoreReviewService {
            return new StoreReviewService(
                $di->getShared('storeRepository'),
                $di->getShared('notificationService')
            );
        });

        // Notification repository injection
        $di->setShared(
            "notificationRepository",
            function () use ($di): NotificationRepository {
                return new PdoNotificationRepository($di->getShared('db'));
            },
        );

        // The notification service depends on the notification repository.
        $di->setShared(
            "notificationService",
            function () use ($di): NotificationService {
                return new NotificationService($di->getShared("notificationRepository"));
            },
        );

        $di->setShared('vendorReviewService', function () use ($di) {
                return new VendorReviewService(
                    $di->getShared('vendorRepository'),
                    $di->getShared('notificationService'),
                );
            }
        );

        $di->setShared('rejectVendorValidator', function () use ($di) {
                return new RejectVendorValidator();
            }
        );

        $di->setShared(
            'productRepository',
            fn() => new PdoProductRepository($di->getShared('db'))
        );

        $di->setShared(
            'storeAccessChecker',
            fn() => new PdoStoreAccessChecker($di->getShared('db'))
        );

        $di->setShared(
            'productService',
            fn(): ProductService => new ProductService(
                $di->getShared('productRepository'),
                $di->getShared('storeAccessChecker'),
                $di->getShared('productVariantRepository'),
                $di->getShared('inventoryRepository'),
                $di->getShared('inventoryMovementRepository'),
                $di->getShared('productCategoryRepository'),
                $di->getShared('db'),
            )
        );

        $di->setShared(
            'productVariantRepository',
            fn(): ProductVariantRepository => new PdoProductVariantRepository($di->getShared('db'))
        );

        $di->setShared(
            'inventoryRepository',
            fn(): InventoryRepository => new PdoInventoryRepository($di->getShared('db'))
        );

        $di->setShared(
            'inventoryMovementRepository',
            fn(): InventoryMovementRepository => new PdoInventoryMovementRepository($di->getShared('db'))
        );

        $di->setShared(
            'productCategoryRepository',
            fn(): ProductCategoryRepository => new PdoProductCategoryRepository($di->getShared('db'))
        );

        $di->setShared(
            'categoryRepository',
            fn(): CategoryRepository => new PdoCategoryRepository($di->getShared('db'))
        );

        $di->setShared(
            'categoryService',
            fn(): CategoryService => new CategoryService($di->getShared('categoryRepository'))
        );

        $di->setShared('cartRepository', function () use ($di): CartRepository {
            return new PdoCartRepository($di->getShared('db'));
        });

        $di->setShared('cartService', function () use ($di): CartService {
            return new CartService(
                $di->getShared('cartRepository')
            );
        });

        //Dispatcher Injection
        $di->setShared('dispatcher', function () use ($di): Dispatcher {
            $dispatcher = new Dispatcher();
            $eventsManager = new EventsManager();
            $eventsManager->attach('dispatch:beforeExecuteRoute', $di->getShared('jwtAuthMiddleware'));
            $eventsManager->attach('dispatch:beforeExecuteRoute', $di->getShared('authorizationMiddleware'));

            $dispatcher->setEventsManager($eventsManager);

            return $dispatcher;
        });

        $application = new Application($di);
        return $application;
    }
}
