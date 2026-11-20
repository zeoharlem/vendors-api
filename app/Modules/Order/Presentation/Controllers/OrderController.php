<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Controllers;

use App\Modules\Order\Application\Dtos\CheckoutDto;
use DomainException;
use InvalidArgumentException;
use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;
use Throwable;

final class OrderController extends Controller
{
    public function checkoutAction(): Response
    {
        try {
            $body = $this->request->getJsonRawBody(true);

            if (!is_array($body)) {
                throw new InvalidArgumentException('A valid JSON request body is required.');
            }

            $customerId = (string) $this->dispatcher->getParameter('userAuthId');

            $idempotencyKey = trim((string) $this->request->getHeader('Idempotency-Key'));

            if ($idempotencyKey === '') {
                throw new InvalidArgumentException(
                    'The Idempotency-Key request header is required.'
                );
            }

            $dto = CheckoutDto::fromArray($body);

            $order = $this->di
                ->getShared('checkoutService')
                ->checkout($customerId, $dto, $idempotencyKey);

            return $this->jsonResponder->success($order, 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error('UNPROCESSABLE_ENTITY', $exception->getMessage(), 422);
        } catch (DomainException $exception) {
            return $this->jsonResponder->error('CONFLICT', $exception->getMessage(), 409);
        } catch (Throwable $exception) {
            error_log((string) $exception->getMessage());
            return $this->jsonResponder->error('INTERNAL_SERVER_ERROR', 'Unable to complete checkout at this time.', 500);
        }
    }

    public function showAction(): Response
    {
        try {
            $customerId = (string) $this->dispatcher->getParameter('userAuthId');
            $orderId = (string) $this->dispatcher->getParameter('id');

            $order = $this->di
                ->getShared('checkoutService')
                ->getOrder($customerId, $orderId);

            if ($order === null) {
                return $this->jsonResponder->error('NOT_FOUND', 'Order not found.', 404);
            }

            return $this->jsonResponder->success($order);
        } catch (Throwable $exception) {
            error_log((string) $exception->getMessage());
            return $this->jsonResponder->error('INTERNAL_SERVER_ERROR', 'Unable to retrieve the order.', 500);
        }
    }


    public function adminIndexAction(): Response
    {
        try {
            $filters = [
                'page' => $this->request->getQuery('page', 'int', 1),
                'per_page' => $this->request->getQuery('per_page', 'int', 20),
                'search' => $this->request->getQuery('search', 'string'),
                'status' => $this->request->getQuery('status', 'string'),
                'payment_status' => $this->request->getQuery('payment_status', 'string'),
                'date_from' => $this->request->getQuery('date_from', 'string'),
                'date_to' => $this->request->getQuery('date_to', 'string'),
            ];

            $result = $this->di
                ->getShared('checkoutService')
                ->listAdminOrders($filters);

            return $this->jsonResponder->success($result);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            error_log((string) $exception);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve orders at this time.'.$exception->getMessage(),
                500
            );
        }
    }

    public function adminShowAction(): Response
    {
        try {
            $orderId = (string) $this->dispatcher->getParameter('id');

            if ($orderId === '') {
                throw new InvalidArgumentException('A valid order ID is required.');
            }

            $order = $this->di->getShared('checkoutService')->getAdminOrder($orderId);

            if ($order === null) {
                return $this->jsonResponder->error(
                    'NOT_FOUND',
                    'Order not found.',
                    404
                );
            }

            return $this->jsonResponder->success($order);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            error_log((string) $exception);

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve order details.'.$exception->getMessage(),
                500
            );
        }
    }

    public function adminCancelAction(): Response
    {
        try {
            $orderId = trim(
                (string) $this->dispatcher->getParameter('id', '')
            );

            $adminId = trim(
                (string) $this->dispatcher->getParameter('userAuthId', '')
            );

            if ($adminId === '') {
                return $this->jsonResponder->error(
                    'UNAUTHORIZED',
                    'Authentication is required.',
                    401
                );
            }

            $body = $this->request->getJsonRawBody(true);

            if (!is_array($body)) {
                throw new InvalidArgumentException(
                    'A valid JSON request body is required.'
                );
            }

            $reason = trim((string) ($body['reason'] ?? ''));

            $order = $this->di
                ->getShared('checkoutService')
                ->cancelAdminOrder($orderId, $adminId, $reason);

            return $this->jsonResponder->success($order);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $exception->getMessage(),
                422
            );
        } catch (DomainException $exception) {
            return $this->jsonResponder->error(
                'CONFLICT',
                $exception->getMessage(),
                409
            );
        } catch (Throwable $exception) {
            error_log(
                'Admin order cancellation failed: ' . (string) $exception
            );

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to cancel the order at this time.',
                500
            );
        }
    }
}