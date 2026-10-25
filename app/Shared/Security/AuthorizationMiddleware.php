<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Http\JsonResponder;
use Phalcon\Events\Event;
use Phalcon\Mvc\Dispatcher;
use Throwable;

final class AuthorizationMiddleware
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly JsonResponder $jsonResponder,
    ) {
    }

    /**
     * Maps controller/action pairs to the required ACL resource/action.
     *
     * Add entries for every protected endpoint in your API.
     */
    private const PERMISSIONS = [
        'product' => [
            'index' => ['products', 'view'],
            'show' => ['products', 'view'],
            'create' => ['products', 'create'],
            'update' => ['products', 'update'],
            'delete' => ['products', 'delete'],
        ],

        'vendor' => [
            'createprofile' => ['vendors', 'apply'],
            'me' => ['vendors', 'view'],
        ],

        'stores' => [
            'index' => ['stores', 'view'],
            'show' => ['stores', 'view'],
            'create' => ['stores', 'manage'],
            'update' => ['stores', 'manage'],
            'delete' => ['stores', 'manage'],
        ],

        'orders' => [
            'index' => ['orders', 'view'],
            'show' => ['orders', 'view_own'],
            'create' => ['orders', 'create'],
            'cancel' => ['orders', 'cancel_own'],
            'fulfill' => ['orders', 'update_fulfillment'],
        ],
    ];

    /**
     * Runs before the dispatched controller action executes.
     */
    public function beforeExecuteRoute(Event $event, Dispatcher $dispatcher): bool
    {
        $controller = strtolower($dispatcher->getControllerName());
        $action = strtolower($dispatcher->getActionName());

        // Keep public endpoints consistent with JwtAuthMiddleware.
        if ($this->isPublicRoute($controller, $action)) {
            return true;
        }

        $userId = $dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || $userId === '') {
            return $this->unauthorized($dispatcher);
        }

        $permission = self::PERMISSIONS[$controller][$action] ?? null;

        // Deny by default when a protected endpoint has no policy.
        if ($permission === null) {
            error_log(
                sprintf(
                    'Authorization policy missing for controller "%s", action "%s".',
                    $controller,
                    $action,
                ),
            );
            return $this->forbidden($dispatcher);
        }

        [$resource, $requiredAction] = $permission;

        try {
            $allowed = $this->authorization->isAllowed($userId, $resource, $requiredAction);
        } catch (Throwable $exception) {
            error_log('Authorization check failed: ' . $exception->getMessage(), );

            return $this->serverError($dispatcher);
        }

        if (!$allowed) {
            return $this->forbidden($dispatcher);
        }

        return true;
    }

    private function isPublicRoute(
        string $controller,
        string $action,
    ): bool {
        $publicRoutes = [
            'auth' => [
                'index',
                'login',
                'register',
                'refresh',
                'logout',
            ],
            'health' => ['index'],
        ];

        return in_array($action, $publicRoutes[$controller] ?? [], true );
    }

    private function unauthorized(Dispatcher $dispatcher): bool
    {
        $response = $this->jsonResponder->error(
            'UNAUTHORIZED',
            'Authentication required or authenticated identity is missing.',
            401,
        );
        $dispatcher->setReturnedValue($response);
        return false;
    }

    private function forbidden(Dispatcher $dispatcher): bool
    {
        $response = $this->jsonResponder->error(
            'FORBIDDEN',
            'You do not have permission to perform this action.',
            403,
        );
        $dispatcher->setReturnedValue($response);
        return false;
    }

    private function serverError(Dispatcher $dispatcher): bool
    {
        $response = $this->jsonResponder->error(
            'INTERNAL_SERVER_ERROR',
            'Unable to verify access permissions.',
            500,
        );
        $dispatcher->setReturnedValue($response);
        return false;
    }

    // private function respond(Dispatcher $dispatcher, int $statusCode, string $reasonPhrase, string $message, ): bool
    // {
    //     $this->response->setStatusCode($statusCode, $reasonPhrase);

    //     $this->response->setJsonContent(
    //         [
    //             'success' => false,
    //             'message' => $message,
    //         ]
    //     );

    //     $dispatcher->setReturnedValue($this->response);

    //     return false;
    // }

    
    /**
     * Determine whether an authenticated user can access a resource/action.
     *
     * The user ID must come from a successfully verified JWT,
     * never from request input.
     */
    public function isAuthorized(
        string $authenticatedUserId,
        string $resource,
        string $action,
    ): bool {
        return $this->authorization->isAllowed($authenticatedUserId, $resource, $action);
    }
}
