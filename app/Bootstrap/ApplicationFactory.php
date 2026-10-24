<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Bootstrap\Routes\ApiRoutes;
use App\Modules\Auth\Application\Services\LoginUserService;
use App\Modules\Auth\Application\Services\RefreshTokenService;
use App\Modules\Auth\Application\Services\RegisterUserService;
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
