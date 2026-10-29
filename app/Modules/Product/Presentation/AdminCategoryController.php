<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use App\Modules\Product\Application\Dtos\CreateCategoryDto;
use App\Modules\Product\Application\Dtos\UpdateCategoryDto;
use App\Modules\Product\Application\Services\CategoryService;
use DomainException;
use InvalidArgumentException;
use Phalcon\Mvc\Controller;
use Throwable;

final class AdminCategoryController extends Controller
{
    private CategoryService $categoryService;

    public function initialize(): void
    {
        $service = $this->di->getShared('categoryService');

        if (!$service instanceof CategoryService) {
            throw new \RuntimeException(
                'The categoryService dependency is not registered correctly.'
            );
        }
        $this->categoryService = $service;
    }

    public function indexAction(): mixed
    {
        try {
            return $this->jsonResponder->success([
                'categories' => $this->categoryService->listAll(),
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve categories.',
                500
            );
        }
    }

    public function showAction(string $id): mixed
    {
        try {
            return $this->jsonResponder->success([
                'category' => $this->categoryService->get($id),
            ]);
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                $e->getMessage(),
                404
            );
        } catch (Throwable $e) {
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve the category.',
                500
            );
        }
    }

    public function createAction(): mixed
    {
        try {
            $payload = $this->request->getJsonRawBody(true);

            if (!is_array($payload)) {
                return $this->jsonResponder->error(
                    'BAD_REQUEST',
                    'A valid JSON request body is required.',
                    400
                );
            }

            $dto = CreateCategoryDto::fromArray($payload);
            $category = $this->categoryService->create($dto);

            return $this->jsonResponder->success([
                'category' => $category,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $e->getMessage(),
                422
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'CONFLICT',
                $e->getMessage(),
                409
            );
        } catch (Throwable $e) {
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to create the category.',
                500
            );
        }
    }

    public function updateAction(string $id): mixed
    {
        try {
            $payload = $this->request->getJsonRawBody(true);

            if (!is_array($payload)) {
                return $this->jsonResponder->error(
                    'BAD_REQUEST',
                    'A valid JSON request body is required.',
                    400
                );
            }

            $dto = UpdateCategoryDto::fromArray($payload);

            return $this->jsonResponder->success(
                [
                    'category' => $this->categoryService->update($id, $dto),
                ]
            );
        } catch (InvalidArgumentException $e) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $e->getMessage(),
                422
            );
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'CONFLICT',
                $e->getMessage(),
                409
            );
        } catch (Throwable $e) {
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to update the category.',
                500
            );
        }
    }

    public function activateAction(string $id): mixed
    {
        return $this->changeStatus($id, true);
    }

    public function deactivateAction(string $id): mixed
    {
        return $this->changeStatus($id, false);
    }

    private function changeStatus(string $id, bool $active): mixed
    {
        try {
            return $this->jsonResponder->success([
                'category' => $this->categoryService->setActive($id, $active),
            ]);
        } catch (DomainException $e) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $e->getMessage(),
                409
            );
        } catch (Throwable $e) {
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to change category status.',
                500
            );
        }
    }
}