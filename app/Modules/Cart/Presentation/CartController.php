<?php

declare(strict_types=1);

namespace App\Modules\Cart\Presentation;

use App\Modules\Cart\Application\Dtos\AddCartItemDto;
use App\Modules\Cart\Application\Dtos\UpdateCartItemDto;
use App\Modules\Cart\Application\Services\CartService;
use DomainException;
use InvalidArgumentException;
use Phalcon\Mvc\Controller;
use Throwable;

final class CartController extends Controller
{
    public function indexAction(): mixed
    {
        try {
            return $this->jsonResponder->success(
                $this->cartService()->getCart($this->customerId())
            );
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve cart.',
                500
            );
        }
    }

    public function addItemAction(): mixed
    {
        try {
            $body = $this->request->getJsonRawBody(true);

            if (!is_array($body)) {
                throw new InvalidArgumentException(
                    'A valid JSON request body is required.'
                );
            }

            $cart = $this->cartService()->addItem(
                $this->customerId(),
                AddCartItemDto::fromArray($body)
            );

            return $this->jsonResponder->success($cart);
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
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to add item to cart.',
                500
            );
        }
    }

    public function updateItemAction(): mixed
    {
        try {
            $body = $this->request->getJsonRawBody(true);

            if (!is_array($body)) {
                throw new InvalidArgumentException(
                    'A valid JSON request body is required.'
                );
            }

            $cart = $this->cartService()->updateItem(
                $this->customerId(),
                (string) $this->dispatcher->getParameter('id'),
                UpdateCartItemDto::fromArray($body)
            );

            return $this->jsonResponder->success($cart);
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
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to update cart item.',
                500
            );
        }
    }

    public function removeItemAction(): mixed
    {
        try {
            $cart = $this->cartService()->removeItem(
                $this->customerId(),
                (string) $this->dispatcher->getParameter('id')
            );

            return $this->jsonResponder->success($cart);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $exception->getMessage(),
                422
            );
        } catch (DomainException $exception) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                $exception->getMessage(),
                404
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to remove cart item.',
                500
            );
        }
    }

    private function customerId(): string
    {
        $customerId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($customerId) || $customerId === '') {
            throw new InvalidArgumentException(
                'Authenticated customer context is missing.'
            );
        }

        return $customerId;
    }

    private function cartService(): CartService
    {
        return $this->di->getShared('cartService');
    }
}
