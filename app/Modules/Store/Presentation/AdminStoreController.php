<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation;

use App\Modules\Store\Application\Dtos\StoreReviewResponseDto;
use App\Modules\Store\Application\Services\StoreReviewService;
use DomainException;
use Throwable;

final class AdminStoreController extends \Phalcon\Mvc\Controller
{
    public function pendingAction()
    {
        try {
            $limit = (int) $this->request->getQuery('limit', 'int', 20);
            $offset = (int) $this->request->getQuery('offset', 'int', 0);

            $stores = $this->reviewService()->listPending($limit, $offset);

            $data = array_map(
                static fn(array $store): array =>
                    StoreReviewResponseDto::fromArray($store)->toArray(),
                $stores,
            );

            return $this->jsonResponder->success($data, 200, [
                'limit' => max(1, min($limit, 100)),
                'offset' => max(0, $offset),
                'count' => count($data),
            ]);
        } catch (Throwable $exception) {
            return $this->handleUnexpectedError($exception);
        }
    }

    public function showAction()
    {
        try {
            $storeId = (string) $this->dispatcher->getParameter('id');

            $store = $this->reviewService()->getForReview($storeId);

            return $this->jsonResponder->success(
                StoreReviewResponseDto::fromArray($store)->toArray(),
            );
        } catch (DomainException $exception) {
            return $this->handleDomainError($exception);
        } catch (Throwable $exception) {
            return $this->handleUnexpectedError($exception);
        }
    }

    public function approveAction()
    {
        try {
            $storeId = (string) $this->dispatcher->getParameter('id');
            $adminUserId = (string) $this->dispatcher->getParameter('userAuthId');

            $store = $this->reviewService()->approve($storeId, $adminUserId);

            return $this->jsonResponder->success(StoreReviewResponseDto::fromArray($store)->toArray());
        } catch (DomainException $exception) {
            return $this->handleDomainError($exception);
        } catch (Throwable $exception) {
            return $this->handleUnexpectedError($exception);
        }
    }

    public function rejectAction()
    {
        try {
            $storeId = (string) $this->dispatcher->getParameter('id');
            $adminUserId = (string) $this->dispatcher->getParameter('userAuthId');
            $body = $this->request->getJsonRawBody(true);

            if (!is_array($body) || !isset($body['reason']) || !is_string($body['reason'])) {
                return $this->jsonResponder->error(
                    'VALIDATION_FAILED',
                    'A rejection reason is required.',
                    422,
                    ['reason' => 'Provide a string between 10 and 1000 characters.'],
                );
            }

            $store = $this->reviewService()->reject(
                $storeId,
                $adminUserId,
                $body['reason'],
            );

            return $this->jsonResponder->success(
                StoreReviewResponseDto::fromArray($store)->toArray(),
            );
        } catch (DomainException $exception) {
            return $this->handleDomainError($exception);
        } catch (Throwable $exception) {
            return $this->handleUnexpectedError($exception);
        }
    }

    private function reviewService(): StoreReviewService
    {
        return $this->getDI()->getShared('storeReviewService');
    }

    private function handleDomainError(DomainException $exception)
    {
        return match ($exception->getMessage()) {
            'STORE_NOT_FOUND' => $this->jsonResponder->error(
                'NOT_FOUND',
                'The requested store was not found.',
                404,
            ),
            'STORE_NOT_PENDING' => $this->jsonResponder->error(
                'CONFLICT',
                'Only pending stores can be reviewed.',
                409,
            ),
            'STORE_REVIEW_CONFLICT' => $this->jsonResponder->error(
                'CONFLICT',
                'The store could not be reviewed. Refresh and try again.',
                409,
            ),
            'INVALID_REJECTION_REASON' => $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                'The rejection reason must contain between 10 and 1000 characters.',
                422,
                ['reason' => 'Invalid length.'],
            ),
            default => $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'An unexpected error occurred.',
                500,
            ),
        };
    }

    private function handleUnexpectedError(Throwable $exception)
    {
        error_log(sprintf(
            'Admin store review failed: %s in %s:%d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        ));

        return $this->jsonResponder->error(
            'INTERNAL_SERVER_ERROR',
            'An unexpected error occurred.',
            500,
        );
    }
}