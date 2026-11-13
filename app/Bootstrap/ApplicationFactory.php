<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Bootstrap\Routes\ApiRoutes;
use App\Modules\Notification\Infrastructure\Queue\FailedEmailTransport;
use App\Modules\Payout\Infrastructure\Providers\PaystackPayoutProvider;
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
use App\Modules\Dispute\Application\Services\DisputeEvidenceAccessService;
use App\Modules\Dispute\Application\Services\DisputeEvidenceService;
use App\Modules\Order\Domain\Repositories\OrderRepository;
use App\Modules\Order\Infrastructure\Persistence\PdoOrderRepository;
use App\Modules\Order\Application\Services\CheckoutService;
use App\Modules\Payment\Domain\Contracts\PaymentProviderInterface;
use App\Modules\Payment\Domain\Repositories\PaymentRepository;
use App\Modules\Payment\Infrastructure\Persistence\PdoPaymentRepository;
use App\Modules\Payment\Infrastructure\Providers\PaystackPaymentProvider;
use App\Modules\Payment\Application\Services\PaymentService;
use App\Modules\Payment\Infrastructure\Persistence\PdoPaymentEventRepository;
use App\Modules\Payment\Infrastructure\Providers\PaystackWebhookSignatureVerifier;
use App\Modules\Ledger\Domain\Repositories\LedgerRepository;
use App\Modules\Ledger\Infrastructure\Persistence\PdoLedgerRepository;
use App\Modules\Ledger\Application\Services\LedgerPostingService;
use App\Modules\Ledger\Application\Services\CommissionCalculator;
use App\Modules\Ledger\Domain\Contracts\CommissionCalculatorInterface;
use App\Modules\Ledger\Domain\Repositories\VendorPayableRepository;
use App\Modules\Ledger\Infrastructure\Persistence\PdoVendorPayableRepository;
use App\Modules\Notification\Domain\Contracts\EmailSenderInterface;
use App\Modules\Notification\Infrastructure\Email\SendGridEmailSender;
use App\Modules\Product\Application\Contracts\StoreAccessCheckerInterface;
use App\Modules\Vendor\Application\Jobs\AutoAcceptExpiredDeliveriesJob;
use App\Modules\Vendor\Application\Services\VendorOrderService;
use App\Modules\Vendor\Domain\Repository\VendorOrderRepository;
use App\Modules\Vendor\Infrastructure\Persistence\PdoVendorOrderRepository;
use App\Modules\Notification\Infrastructure\Queue\DoctrineConnectionFactory;
use Doctrine\DBAL\Connection;
use App\Modules\Notification\Infrastructure\Queue\MessengerTransportFactory;
use Doctrine\DBAL\Connection as DoctrineDbalConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use App\Modules\Notification\Domain\Contracts\EmailDeliveryRepositoryInterface;
use App\Modules\Notification\Infrastructure\Persistence\PdoEmailDeliveryRepository;
use App\Modules\Notification\Application\Handlers\SendEmailHandler;
use App\Modules\Notification\Application\Messages\SendEmailMessage;
use App\Modules\Notification\Domain\Contracts\EmailTemplateRendererInterface;
use App\Modules\Notification\Infrastructure\Email\MailtrapEmailSender;
use App\Modules\Notification\Infrastructure\Email\PhpEmailTemplateRenderer;
use App\Modules\Notification\Infrastructure\Messenger\EmailMessageSenderLocator;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use RuntimeException;
use App\Modules\Notification\Domain\Contracts\OutboxEventPublisherInterface;
use App\Modules\Notification\Infrastructure\Persistence\PdoOutboxEventPublisher;
use App\Modules\Notification\Application\Handlers\OrderPlacedEmailHandler;
use App\Modules\Notification\Infrastructure\Outbox\OutboxEventDispatcher;
use App\Modules\Notification\Infrastructure\Outbox\Handlers\OrderPlacedOutboxEventHandler;
use App\Modules\Notification\Infrastructure\Outbox\OutboxEventHandlerRegistry;
use App\Modules\Notification\Application\Handlers\PaymentStatusEmailHandler;
use App\Modules\Notification\Infrastructure\Outbox\Handlers\PaymentFailedOutboxEventHandler;
use App\Modules\Notification\Infrastructure\Outbox\Handlers\PaymentSucceededOutboxEventHandler;
use App\Modules\Notification\Application\Handlers\VendorOrderEmailHandler;
use App\Modules\Notification\Infrastructure\Outbox\Handlers\VendorOrderStatusOutboxEventHandler;
use App\Modules\Dispute\Domain\Contracts\DisputePayableManagerInterface;
use App\Modules\Dispute\Domain\Contracts\DisputeTransactionManagerInterface;
use App\Modules\Dispute\Domain\Contracts\DisputeVendorOrderAccessInterface;
use App\Modules\Dispute\Infrastructure\Persistence\PdoDisputePayableManager;
use App\Modules\Dispute\Infrastructure\Persistence\PdoDisputeTransactionManager;
use App\Modules\Dispute\Infrastructure\Persistence\PdoDisputeVendorOrderAccess;
use App\Modules\Dispute\Domain\Contracts\DisputeEvidenceStorageInterface;
use App\Modules\Dispute\Domain\Contracts\DisputeRefundManagerInterface;
use App\Modules\Dispute\Domain\Repositories\DisputeEvidenceRepositoryInterface;
use App\Modules\Dispute\Domain\Repositories\DisputeHistoryRepositoryInterface;
use App\Modules\Dispute\Domain\Repositories\DisputeRepositoryInterface;
use App\Modules\Dispute\Infrastructure\Payments\PaymentServiceDisputeRefundManager;
use App\Modules\Dispute\Infrastructure\Persistence\PdoDisputeEvidenceRepository;
use App\Modules\Dispute\Infrastructure\Storage\LocalDisputeEvidenceStorage;
use App\Modules\Payment\Application\Services\PaystackRefundWebhookService;
use App\Modules\Payout\Application\Services\PayoutAccountService;
use App\Modules\Payout\Application\Services\PayoutService;
use App\Modules\Payout\Application\Services\PaystackTransferWebhookService;
use App\Modules\Payout\Domain\Contracts\PayoutLedgerSettlementInterface;
use App\Modules\Payout\Domain\Contracts\PayoutProviderInterface;
use App\Modules\Payout\Domain\Repositories\PayoutRepositoryInterface;
use App\Modules\Payout\Domain\Repositories\VendorDebtRepositoryInterface;
use App\Modules\Payout\Domain\Repositories\VendorPayoutAccountRepositoryInterface;
use App\Modules\Payout\Infrastructure\Ledger\LedgerPayoutSettlement;
use App\Modules\Payout\Infrastructure\Persistence\PdoPayoutRepository;
use App\Modules\Payout\Infrastructure\Persistence\PdoPayoutReservationRepository;
use App\Modules\Payout\Infrastructure\Persistence\PdoVendorDebtRepository;
use App\Modules\Payout\Infrastructure\Persistence\PdoVendorPayoutAccountRepository;
use App\Modules\Payout\Infrastructure\Providers\MockPayoutProvider;
use App\Shared\Enums\EventTypes;
use App\Modules\Product\Application\Contracts\ProductImageProcessorInterface;
use App\Modules\Product\Application\Contracts\ProductImageStorageInterface;
use App\Modules\Product\Application\Services\ProductImageService;
use App\Modules\Product\Infrastructure\Images\GdProductImageProcessor;
use App\Modules\Product\Infrastructure\Storage\LocalProductImageStorage;
use App\Modules\Product\Infrastructure\Persistence\PdoProductMediaRepository;
use App\Modules\Product\Domain\Repositories\ProductMediaRepository;

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

        $di->setShared(
            Connection::class,
            function () use ($di): Connection {
                return DoctrineConnectionFactory::create($di->getShared('config')['db']);
            }
        );

        $di->setShared(
            DoctrineTransport::class,
            function () use ($di): DoctrineTransport {
                $connection = $di->getShared(Connection::class);

                return MessengerTransportFactory::create($connection, queueName: 'emails');
            }
        );

        $di->setShared(
            FailedEmailTransport::class,
            function () use ($di): DoctrineTransport {
                $connection = $di->getShared(Connection::class);

                return MessengerTransportFactory::create($connection, queueName: 'failed_emails');
            }
        );

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

        $di->setShared(
            'vendorReviewService',
            function () use ($di) {
                return new VendorReviewService(
                    $di->getShared('vendorRepository'),
                    $di->getShared('notificationService'),
                );
            }
        );

        $di->setShared(
            'rejectVendorValidator',
            function () use ($di) {
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
                $di->getShared(StoreAccessCheckerInterface::class),
                $di->getShared('productVariantRepository'),
                $di->getShared('inventoryRepository'),
                $di->getShared('inventoryMovementRepository'),
                $di->getShared('productCategoryRepository'),
                $di->getShared('productMediaRepository'),
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

        $di->setShared(
            OutboxEventPublisherInterface::class,
            function () use ($di) {
                return new PdoOutboxEventPublisher($di->getShared('db'));
            }
        );

        $di->setShared('orderRepository', function () use ($di): OrderRepository {
            return new PdoOrderRepository(
                $di->getShared('db'),
                $di->getShared(OutboxEventPublisherInterface::class)
            );
        });

        $di->setShared('checkoutService', function () use ($di): CheckoutService {
            return new CheckoutService($di->getShared('orderRepository'));
        });

        $di->setShared('paymentRepository', function () use ($di): PaymentRepository {
            return new PdoPaymentRepository(
                $di->getShared('db'),
                $di->getShared(LedgerPostingService::class),
                $di->getShared(OutboxEventPublisherInterface::class)
            );
        });

        $di->setShared('paymentProvider', function (): PaymentProviderInterface {
            $secretKey = getenv('PAYSTACK_SECRET_KEY') ?: ($_ENV['PAYSTACK_SECRET_KEY'] ?? '');
            return new PaystackPaymentProvider($secretKey);
        });

        $di->setShared('paymentService', function () use ($di): PaymentService {
            $callbackUrl = getenv('PAYSTACK_CALLBACK_URL') ?: ($_ENV['PAYSTACK_CALLBACK_URL'] ?? '');

            return new PaymentService(
                paymentRepository: $di->getShared('paymentRepository'),
                paymentProvider: $di->getShared('paymentProvider'),
                callbackUrl: $callbackUrl
            );
        });

        $di->setShared(
            'paymentEventRepository',
            function () use ($di) {
                return new PdoPaymentEventRepository($di->getShared('db'));
            }
        );

        $di->setShared(
            'paystackWebhookSignatureVerifier',
            function () {
                $secretKey = getenv('PAYSTACK_SECRET_KEY') ?: ($_ENV['PAYSTACK_SECRET_KEY'] ?? '');
                return new PaystackWebhookSignatureVerifier((string) $secretKey);
            }
        );

        $di->setShared(
            LedgerRepository::class,
            function () use ($di) {
                return new PdoLedgerRepository($di->getShared('db'));
            }
        );

        $di->setShared(
            CommissionCalculatorInterface::class,
            function () {
                return new CommissionCalculator();
            }
        );

        $di->setShared(
            LedgerPostingService::class,
            function () use ($di) {
                return new LedgerPostingService(
                    $di->getShared(LedgerRepository::class),
                    $di->getShared(VendorPayableRepository::class),
                    $di->getShared(CommissionCalculatorInterface::class),
                    (int) ($_ENV['PLATFORM_DEFAULT_COMMISSION_BPS'] ?? 1000)
                );
            }
        );

        $di->setShared(
            VendorPayableRepository::class,
            function () use ($di) {
                return new PdoVendorPayableRepository($di->getShared('db'));
            }
        );


        $di->setShared(
            StoreAccessCheckerInterface::class,
            function () use ($di) {
                return new PdoStoreAccessChecker($di->getShared('db'));
            }
        );

        $di->setShared(
            VendorOrderRepository::class,
            function () use ($di) {
                return new PdoVendorOrderRepository(
                    $di->getShared('db'),
                    $di->getShared(OutboxEventPublisherInterface::class)
                );
            }
        );

        $di->setShared(
            VendorOrderService::class,
            function () use ($di) {
                return new VendorOrderService(
                    $di->getShared(VendorOrderRepository::class),
                    $di->getShared(StoreAccessCheckerInterface::class)
                );
            }
        );

        $di->setShared(
            AutoAcceptExpiredDeliveriesJob::class,
            function () use ($di) {
                return new AutoAcceptExpiredDeliveriesJob(
                    $di->getShared(VendorOrderRepository::class)
                );
            }
        );

        $di->setShared(
            EmailSenderInterface::class,
            function () {
                return new SendGridEmailSender(
                    apiKey: (string) ($_ENV['SENDGRID_API_KEY'] ?? ''),
                    fromEmail: (string) ($_ENV['MAIL_FROM_EMAIL'] ?? ''),
                    fromName: (string) ($_ENV['MAIL_FROM_NAME'] ?? 'Marketplace'),
                );
            }
        );

        $di->setShared(
            EmailDeliveryRepositoryInterface::class,
            function () use ($di) {
                return new PdoEmailDeliveryRepository($di->getShared('db'));
            }
        );

        $di->setShared(
            EmailTemplateRendererInterface::class,
            function () {
                return new PhpEmailTemplateRenderer();
            }
        );

        $di->setShared(
            SendEmailHandler::class,
            function () use ($di) {
                return new SendEmailHandler(
                    deliveryRepository: $di->getShared(
                        EmailDeliveryRepositoryInterface::class
                    ),
                    templateRenderer: $di->getShared(
                        EmailTemplateRendererInterface::class
                    ),
                    emailSender: $di->getShared(
                        EmailSenderInterface::class
                    ),
                );
            }
        );

        $di->setShared(
            EmailMessageSenderLocator::class,
            function () use ($di) {
                return new EmailMessageSenderLocator($di->getShared(DoctrineTransport::class));
            }
        );

        $di->setShared(
            MessageBusInterface::class,
            function () use ($di) {
                $handler = $di->getShared(SendEmailHandler::class);

                $handlersLocator = new HandlersLocator(
                    [
                        SendEmailMessage::class => [$handler],
                    ]
                );

                return new MessageBus(
                    [
                        new SendMessageMiddleware($di->getShared(EmailMessageSenderLocator::class)),
                        new HandleMessageMiddleware($handlersLocator),
                    ]
                );
            }
        );


        $di->setShared(
            EmailSenderInterface::class,
            function () {
                $getEnv = static function (string $key, mixed $default = null): mixed {
                    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

                    return $value !== false && $value !== null
                        ? $value
                        : $default;
                };

                $provider = strtolower(
                    (string) $getEnv('MAIL_PROVIDER', 'mailtrap')
                );

                if ($provider !== 'mailtrap') {
                    throw new RuntimeException(
                        sprintf('Unsupported email provider: %s', $provider)
                    );
                }

                $useSandbox = filter_var(
                    $getEnv('MAILTRAP_USE_SANDBOX', 'true'),
                    FILTER_VALIDATE_BOOLEAN
                );

                if (!$useSandbox) {
                    throw new RuntimeException(
                        'This Mailtrap adapter is currently restricted to Sandbox mode.'
                    );
                }

                return new MailtrapEmailSender(
                    apiKey: (string) $getEnv('MAILTRAP_API_KEY', ''),
                    inboxId: (int) $getEnv('MAILTRAP_INBOX_ID', 0),
                    fromEmail: (string) $getEnv('MAIL_FROM_EMAIL', 'smtp@mailtrap.io'),
                    fromName: (string) $getEnv('MAIL_FROM_NAME', 'Vendors API'),
                );
            }
        );

        $di->setShared(
            OrderPlacedEmailHandler::class,
            function () use ($di) {
                return new OrderPlacedEmailHandler(
                    db: $di->getShared('db'),
                    deliveryRepository: $di->getShared(EmailDeliveryRepositoryInterface::class),
                    messageBus: $di->getShared(MessageBusInterface::class),
                );
            }
        );

        $di->setShared(
            OutboxEventDispatcher::class,
            function () use ($di) {
                return new OutboxEventDispatcher(
                    db: $di->getShared('db'),
                    handlerRegistry: $di->getShared(OutboxEventHandlerRegistry::class),
                );
            }
        );

        $di->setShared(
            OrderPlacedOutboxEventHandler::class,
            function () use ($di) {
                return new OrderPlacedOutboxEventHandler(
                    orderPlacedEmailHandler: $di->getShared(OrderPlacedEmailHandler::class),
                );
            },
        );

        $di->setShared(
            VendorOrderEmailHandler::class,
            function () use ($di) {
                return new VendorOrderEmailHandler(
                    db: $di->getShared('db'),
                    deliveryRepository: $di->getShared(EmailDeliveryRepositoryInterface::class),
                    messageBus: $di->getShared(MessageBusInterface::class),
                );
            },
        );

        $di->setShared(
            'vendorOrderAcceptedOutboxEventHandler',
            function () use ($di) {
                return new VendorOrderStatusOutboxEventHandler(
                    emailHandler: $di->getShared(VendorOrderEmailHandler::class),
                    expectedEventType: EventTypes::VENDOR_ORDER_ACCEPTED,
                );
            },
        );

        $di->setShared(
            'vendorOrderShippedOutboxEventHandler',
            function () use ($di) {
                return new VendorOrderStatusOutboxEventHandler(
                    emailHandler: $di->getShared(VendorOrderEmailHandler::class),
                    expectedEventType: EventTypes::VENDOR_ORDER_SHIPPED,
                );
            },
        );

        $di->setShared(
            'vendorOrderCompletedOutboxEventHandler',
            function () use ($di) {
                return new VendorOrderStatusOutboxEventHandler(
                    emailHandler: $di->getShared(VendorOrderEmailHandler::class),
                    expectedEventType: EventTypes::VENDOR_ORDER_COMPLETED,
                );
            },
        );

        $di->setShared(
            OutboxEventHandlerRegistry::class,
            function () use ($di) {
                return new OutboxEventHandlerRegistry(
                    handlers: [
                        EventTypes::ORDER_PLACED->value => $di->getShared(
                            OrderPlacedOutboxEventHandler::class
                        ),
                        EventTypes::PAYMENT_SUCCEEDED->value => $di->getShared(
                            PaymentSucceededOutboxEventHandler::class
                        ),
                        EventTypes::PAYMENT_FAILED->value => $di->getShared(
                            PaymentFailedOutboxEventHandler::class
                        ),
                        EventTypes::VENDOR_ORDER_ACCEPTED->value => $di->getShared(
                            'vendorOrderAcceptedOutboxEventHandler'
                        ),
                        EventTypes::VENDOR_ORDER_SHIPPED->value => $di->getShared(
                            'vendorOrderShippedOutboxEventHandler'
                        ),
                        EventTypes::VENDOR_ORDER_COMPLETED->value => $di->getShared(
                            'vendorOrderCompletedOutboxEventHandler'
                        ),
                    ],
                );
            },
        );

        $di->setShared(
            PaymentStatusEmailHandler::class,
            function () use ($di) {
                return new PaymentStatusEmailHandler(
                    db: $di->getShared('db'),
                    deliveryRepository: $di->getShared(EmailDeliveryRepositoryInterface::class),
                    messageBus: $di->getShared(MessageBusInterface::class),
                );
            },
        );

        $di->setShared(
            PaymentSucceededOutboxEventHandler::class,
            function () use ($di) {
                return new PaymentSucceededOutboxEventHandler(
                    paymentStatusEmailHandler: $di->getShared(PaymentStatusEmailHandler::class),
                );
            },
        );

        $di->setShared(
            PaymentFailedOutboxEventHandler::class,
            function () use ($di) {
                return new PaymentFailedOutboxEventHandler(
                    paymentStatusEmailHandler: $di->getShared(PaymentStatusEmailHandler::class),
                );
            },
        );

        // Dispute Managers
        $di->setShared(
            DisputeTransactionManagerInterface::class,
            function () use ($di) {
                return new PdoDisputeTransactionManager($di->getShared('db'));
            }
        );

        $di->setShared(
            DisputePayableManagerInterface::class,
            function () use ($di) {
                return new PdoDisputePayableManager($di->getShared('db'));
            }
        );

        $di->setShared(
            DisputeVendorOrderAccessInterface::class,
            function () use ($di) {
                return new PdoDisputeVendorOrderAccess($di->getShared('db'));
            }
        );

        // Dispute Evidence Storage
        $di->setShared(
            DisputeEvidenceStorageInterface::class,
            function () {
                $storageDirectory = getenv('DISPUTE_EVIDENCE_STORAGE_PATH');

                if ($storageDirectory === false || trim($storageDirectory) === '') {
                    throw new RuntimeException('DISPUTE_EVIDENCE_STORAGE_PATH is not configured.');
                }

                return new LocalDisputeEvidenceStorage($storageDirectory);
            }
        );

        // Dispute Evidence Service
        $di->setShared(
            DisputeEvidenceService::class,
            function () use ($di) {
                return new DisputeEvidenceService(
                    $di->getShared(DisputeRepositoryInterface::class),
                    $di->getShared(DisputeEvidenceRepositoryInterface::class),
                    $di->getShared(DisputeHistoryRepositoryInterface::class),
                    $di->getShared(DisputeEvidenceStorageInterface::class),
                    $di->getShared(DisputeTransactionManagerInterface::class),
                );
            }
        );


        $di->setShared(
            DisputeEvidenceRepositoryInterface::class,
            function () use ($di) {
                return new PdoDisputeEvidenceRepository($di->getShared('db'));
            }
        );

        $di->setShared(
            DisputeEvidenceAccessService::class,
            function () use ($di) {
                return new DisputeEvidenceAccessService(
                    $di->getShared(DisputeRepositoryInterface::class),
                    $di->getShared(DisputeEvidenceRepositoryInterface::class),
                    $di->getShared(DisputeHistoryRepositoryInterface::class),
                    $di->getShared(DisputeEvidenceStorageInterface::class),
                    $di->getShared(DisputeTransactionManagerInterface::class),
                );
            }
        );

        $di->setShared(DisputeRefundManagerInterface::class, function () use ($di) {
            return new PaymentServiceDisputeRefundManager($di->getShared(PaymentService::class));
        });

        $di->setShared(
            PaystackRefundWebhookService::class,
            function () use ($di) {
                return new PaystackRefundWebhookService(
                    $di->getShared(PaymentRepository::class),
                );
            }
        );

        // Payout repository
        $di->setShared(
            PayoutRepositoryInterface::class,
            function () use ($di): PayoutRepositoryInterface {
                return new PdoPayoutRepository($di->getShared('db'));
            }
        );

        // Vendor debt repository
        $di->setShared(
            VendorDebtRepositoryInterface::class,
            function () use ($di): VendorDebtRepositoryInterface {
                return new PdoVendorDebtRepository($di->getShared('db'));
            }
        );

        // Payout reservation repository
        $di->setShared(
            PdoPayoutReservationRepository::class,
            function () use ($di): PdoPayoutReservationRepository {
                return new PdoPayoutReservationRepository(
                    $di->getShared('db'),
                    $di->getShared(VendorDebtRepositoryInterface::class),
                );
            }
        );

        // Payout ledger settlement adapter
        $di->setShared(
            PayoutLedgerSettlementInterface::class,
            function () use ($di): PayoutLedgerSettlementInterface {
                return new LedgerPayoutSettlement(
                    $di->getShared(LedgerPostingService::class),
                );
            }
        );

        // Payout application service
        $di->setShared(
            PayoutService::class,
            function () use ($di): PayoutService {
                return new PayoutService(
                    db: $di->getShared('db'),
                    reservationRepository: $di->getShared(
                        PdoPayoutReservationRepository::class
                    ),
                    vendorDebtRepository: $di->getShared(
                        VendorDebtRepositoryInterface::class
                    ),
                    payoutProvider: $di->getShared(
                        PayoutProviderInterface::class
                    ),
                    ledgerSettlement: $di->getShared(
                        PayoutLedgerSettlementInterface::class
                    ),
                    storeAccessChecker: $di->getShared(
                        StoreAccessCheckerInterface::class
                    ),
                );
            }
        );

        // Paystack transfer webhook service
        $di->setShared(
            PaystackTransferWebhookService::class,
            function () use ($di): PaystackTransferWebhookService {
                return new PaystackTransferWebhookService(
                    db: $di->getShared('db'),
                    vendorDebtRepository: $di->getShared(
                        VendorDebtRepositoryInterface::class
                    ),
                    ledgerSettlement: $di->getShared(
                        PayoutLedgerSettlementInterface::class
                    ),
                );
            }
        );

        // $di->setShared(
        //     PayoutProviderInterface::class,
        //     function (): PayoutProviderInterface {
        //         $secretKey = getenv('PAYSTACK_SECRET_KEY') ?: ($_ENV['PAYSTACK_SECRET_KEY'] ?? '');

        //         return new PaystackPayoutProvider((string) $secretKey);
        //     }
        // );

        $di->setShared(
            PayoutProviderInterface::class,
            function () {
                $providerName = trim((string) (
                    $_ENV['PAYOUT_PROVIDER']
                    ?? $_SERVER['PAYOUT_PROVIDER']
                    ?? getenv('PAYOUT_PROVIDER')
                    ?: 'paystack'
                ));

                $appEnvironment = strtolower(trim((string) (
                    $_ENV['APP_ENV']
                    ?? $_SERVER['APP_ENV']
                    ?? getenv('APP_ENV')
                    ?: ''
                )));

                if (strtolower($providerName) === 'mock') {
                    if (
                        !in_array($appEnvironment, ['local', 'development', 'testing'], true)
                    ) {
                        throw new RuntimeException(
                            'The mock payout provider can only run in local, development, or testing environments.'
                        );
                    }

                    return new MockPayoutProvider();
                }

                if (strtolower($providerName) !== 'paystack') {
                    throw new RuntimeException(
                        'Unsupported payout provider: ' . $providerName
                    );
                }

                $secretKey = (string) (
                    $_ENV['PAYSTACK_SECRET_KEY']
                    ?? $_SERVER['PAYSTACK_SECRET_KEY']
                    ?? getenv('PAYSTACK_SECRET_KEY')
                    ?: ''
                );

                return new PaystackPayoutProvider($secretKey);
            }
        );

        $di->setShared(
            VendorPayoutAccountRepositoryInterface::class,
            fn() => new PdoVendorPayoutAccountRepository($di->getShared('db'))
        );

        $di->setShared(
            PayoutAccountService::class,
            fn() => new PayoutAccountService(
                payoutProvider: $di->getShared(PayoutProviderInterface::class),
                storeAccessChecker: $di->getShared(StoreAccessCheckerInterface::class),
                accountRepository: $di->getShared(VendorPayoutAccountRepositoryInterface::class)
            )
        );

        // Product Media Repository
        $di->setShared(
            'productMediaRepository',
            fn(): ProductMediaRepository => new PdoProductMediaRepository(
                $di->getShared('db')
            )
        );

        $di->setShared(
            ProductImageProcessorInterface::class,
            fn(): ProductImageProcessorInterface => new GdProductImageProcessor()
        );

        $di->setShared(
            ProductImageStorageInterface::class,
            fn(): ProductImageStorageInterface => new LocalProductImageStorage(
                $root . '/storage'
            )
        );

        $di->setShared(
            'productImageService',
            fn(): ProductImageService => new ProductImageService(
                $di->getShared('productRepository'),
                $di->getShared('productMediaRepository'),
                $di->getShared(StoreAccessCheckerInterface::class),
                $di->getShared(ProductImageProcessorInterface::class),
                $di->getShared(ProductImageStorageInterface::class),
                $di->getShared('db')
            )
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
