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
}