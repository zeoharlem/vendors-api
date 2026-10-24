<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Modules\Auth\Application\Services\RefreshTokenService;
use App\Shared\Http\JsonResponder;
use Phalcon\Events\Event;
use Phalcon\Http\Request;
use Phalcon\Http\Response;
use Phalcon\Mvc\Dispatcher;
use Throwable;

final class JwtAuthMiddleware
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly Request $request,
        private readonly JsonResponder $jsonResponder,
        private readonly RefreshTokenService $refreshTokenService,
    ) {
    }

    /**
     * Runs before the dispatched controller action executes.
     */
    public function beforeExecuteRoute(Event $event, Dispatcher $dispatcher): bool
    {
        $controller = strtolower($dispatcher->getControllerName());
        $action = strtolower($dispatcher->getActionName());

        if ($this->isPublicRoute($controller, $action)) {
            return true;
        }

        //$authorization = trim($this->request->getHeader('Authorization'));
        $authorization = trim(
            $this->request->getHeader('Authorization') ?: ($_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '')
        );

        if (
            !preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)
        ) {
            return $this->unauthorized($dispatcher);
        }

        try {
            $claims = $this->jwtService->validate($matches[1]);
            if ($this->refreshTokenService->isFamilyRevoked($claims->family_id)) {
                return $this->unauthorized($dispatcher);
            }
        } catch (Throwable $exception) {
            error_log('JWT validation error: ' . ($exception->getPrevious()?->getMessage() ?? $exception->getMessage()));
            return $this->unauthorized($dispatcher);
        }

        // Make the validated identity available to the controller.
        $dispatcher->setParameter('userAuth', $claims);
        $dispatcher->setParameter('userAuthId', $claims->sub);

        return true;
    }

    private function isPublicRoute(string $controller, string $action): bool
    {
        $publicRoutes = [
            'auth' => ['index', 'login', 'register', 'refresh', 'logout'],
            'health' => ['index'],
        ];

        return in_array($action, $publicRoutes[$controller] ?? [], true);
    }

    private function unauthorized(Dispatcher $dispatcher): bool
    {
        $response = $this->jsonResponder->error('UNAUTHORIZED', 'Authentication required or access token is invalid.', 401);
        $dispatcher->setReturnedValue($response);
        return false;
    }
}
