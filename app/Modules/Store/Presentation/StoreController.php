<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation;

use App\Modules\Store\Application\Dtos\CreateStoreRequestDTO;
use App\Modules\Store\Application\Services\StoreService;
use App\Modules\Store\Application\Validators\CreateStoreValidator;
use Phalcon\Mvc\Controller;
use Throwable;

final class StoreController extends Controller
{
    public function createAction(): mixed
    {
        $userId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || $userId === '') {
            return $this->jsonResponder->error(
                'UNAUTHENTICATED',
                'Unauthenticated user.',
                401,
            );
        }

        try {
            // 1. Build a DTO from the incoming request.
            $requestDTO = CreateStoreRequestDTO::fromRequest($this->request);

            // 2. Validate the DTO data.
            $validator = new CreateStoreValidator();
            $messages = $validator->validate($requestDTO->toArray());

            if (count($messages) > 0) {
                $errors = [];

                foreach ($messages as $message) {
                    $field = $message->getField();
                    $errors[$field][] = $message->getMessage();
                }

                return $this->jsonResponder->error(
                    'UPROCESSABLE_ENTITY',
                    'Validation failed.',
                    422,
                    ['fields' => $errors],
                );
            }

            // 3. Delegate business logic to the application service.
            /** @var StoreService $service */
            $service = $this->di->getShared('storeService');

            $responseDTO = $service->createForUser($userId, $requestDTO);

            // 4. Return the response DTO using the standard JSON envelope.
            return $this->jsonResponder->success($responseDTO->toArray(), 201);
        } catch (\DomainException $exception) {
            return $this->jsonResponder->error(
                'CONFLICT',
                $exception->getMessage(),
                409,
            );
        } catch (Throwable $exception) {
            error_log(
                'Store creation failed: ' . $exception->getMessage(),
            );

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to create store.',
                500,
            );
        }
    }

    public function indexAction(): mixed
    {
        try {
            $limit = $this->request->getQuery('limit', 'int', 20);
            $offset = $this->request->getQuery('offset', 'int', 0);

            $limit = max(1, min((int) $limit, 100));
            $offset = max(0, (int) $offset);

            /** @var StoreService $service */
            $service = $this->di->getShared('storeService');

            $stores = array_map(
                static fn($store) => $store->toArray(),
                $service->listPublic($limit, $offset),
            );

            return $this->jsonResponder->success(
                $stores,
                200,
                [
                    'pagination' => [
                        'limit' => $limit,
                        'offset' => $offset,
                        'count' => count($stores),
                    ],
                ],
            );
        } catch (Throwable $exception) {
            error_log('Public store listing failed: ' . $exception->getMessage());

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve stores.',
                500,
            );
        }
    }
    public function showAction(): mixed
    {
        $id = $this->dispatcher->getParameter('id');

        if (
            !is_string($id) || !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $id,
            )
        ) {
            return $this->jsonResponder->error(
                'BAD_REQUEST',
                'A valid store ID is required.',
                400,
            );
        }

        try {
            /** @var StoreService $service */
            $service = $this->di->getShared('storeService');

            $store = $service->getPublicById($id);

            if ($store === null) {
                return $this->jsonResponder->error(
                    'NOT_FOUND',
                    'Store not found.',
                    404,
                );
            }

            return $this->jsonResponder->success($store->toArray());
        } catch (Throwable $exception) {
            error_log('Store retrieval failed: ' . $exception->getMessage());

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve store.',
                500,
            );
        }
    }

    public function mineAction(): mixed
    {
        $userId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || $userId === '') {
            return $this->jsonResponder->error(
                'UNAUTHENTICATED',
                'Authentication required.',
                401,
            );
        }

        try {
            /** @var StoreService $service */
            $service = $this->di->getShared('storeService');

            $stores = array_map(
                static fn($store) => $store->toArray(),
                $service->listForUser($userId),
            );

            return $this->jsonResponder->success($stores);
        } catch (\DomainException $exception) {
            return $this->jsonResponder->error(
                'CONFLICT',
                $exception->getMessage(),
                409
            );
        } catch (Throwable $exception) {
            error_log('Vendor store listing failed: ' . $exception->getMessage());

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve your stores.',
                500,
            );
        }
    }
}