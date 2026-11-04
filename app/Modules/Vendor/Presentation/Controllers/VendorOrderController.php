<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Presentation\Controllers;

use App\Modules\Vendor\Application\Dto\ListVendorOrdersDto;
use App\Modules\Vendor\Application\Dto\ShipVendorOrderDto;
use App\Modules\Vendor\Application\Services\VendorOrderService;
use InvalidArgumentException;
use Phalcon\Http\ResponseInterface;
use Phalcon\Mvc\Controller;
use Throwable;
use RuntimeException;

final class VendorOrderController extends Controller
{
    private function service(): VendorOrderService
    {
        return $this->di->getShared(VendorOrderService::class);
    }

    private function authenticatedUserId(): string
    {
        $userId = (string) $this->dispatcher->getParam('userAuthId');

        if ($userId === '') {
            throw new \RuntimeException(
                'Authenticated user could not be resolved.'
            );
        }

        return $userId;
    }

    public function indexAction(): mixed
    {
        try {
            $dto = ListVendorOrdersDto::fromArray([
                'store_id' => $this->request->getQuery('store_id'),
                'status' => $this->request->getQuery('status'),
                'page' => $this->request->getQuery('page'),
                'per_page' => $this->request->getQuery('per_page'),
            ]);

            $result = $this->service()->list(
                $this->authenticatedUserId(),
                $dto->storeId,
                $dto->status,
                $dto->page,
                $dto->perPage
            );

            return $this->jsonResponder->success(
                $result,
                'Vendor orders retrieved successfully.'
            );
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'VALIDATION_ERROR',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'VENDOR_ORDERS_RETRIEVAL_FAILED',
                $exception->getMessage(),
                500
            );
        }
    }

    public function showAction(string $id): mixed
    {
        try {
            $storeId = trim(
                (string) $this->request->getQuery('store_id')
            );

            if ($storeId === '') {
                throw new InvalidArgumentException(
                    'Store ID is required.'
                );
            }

            $result = $this->service()->get(
                $this->authenticatedUserId(),
                $storeId,
                $id
            );

            return $this->jsonResponder->success(
                $result,
                'Vendor order retrieved successfully.'
            );
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'VALIDATION_ERROR',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'VENDOR_ORDER_RETRIEVAL_FAILED',
                $exception->getMessage(),
                404
            );
        }
    }

    public function acceptAction(string $id): mixed
    {
        return $this->executeTransition(
            fn() => $this->service()->accept(
                $this->authenticatedUserId(),
                $this->requiredStoreId(),
                $id
            ),
            'Vendor order accepted successfully.',
            'VENDOR_ORDER_ACCEPT_FAILED'
        );
    }

    public function processingAction(string $id): mixed
    {
        return $this->executeTransition(
            fn() => $this->service()->startProcessing(
                $this->authenticatedUserId(),
                $this->requiredStoreId(),
                $id
            ),
            'Vendor order moved to processing successfully.',
            'VENDOR_ORDER_PROCESSING_FAILED'
        );
    }

    public function readyToShipAction(string $id): mixed
    {
        return $this->executeTransition(
            fn() => $this->service()->markReadyToShip(
                $this->authenticatedUserId(),
                $this->requiredStoreId(),
                $id
            ),
            'Vendor order marked as ready to ship.',
            'VENDOR_ORDER_READY_TO_SHIP_FAILED'
        );
    }

    public function shipAction(string $id): mixed
    {
        try {
            $dto = ShipVendorOrderDto::fromArray([
                'store_id' => $this->request->getPost('store_id'),
                'tracking_number' => $this->request->getPost('tracking_number'),
                'tracking_url' => $this->request->getPost('tracking_url'),
            ]);

            $result = $this->service()->ship(
                $this->authenticatedUserId(),
                $dto->storeId,
                $id,
                $dto->trackingNumber,
                $dto->trackingUrl
            );

            return $this->jsonResponder->success(
                $result,
                'Vendor order shipped successfully.'
            );
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'VALIDATION_ERROR',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'VENDOR_ORDER_SHIPMENT_FAILED',
                $exception->getMessage(),
                400
            );
        }
    }

    public function deliveredAction(string $id): ResponseInterface
    {
        try {
            $storeId = $this->requiredStoreId();

            $vendorOrder = $this->service()->markDelivered(
                $this->authenticatedUserId(),
                $storeId,
                $id
            );

            return $this->jsonResponder->success(
                $vendorOrder,
                'Vendor order marked as delivered.'
            );
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                $exception->getMessage(),
                400
            );
        }
    }

    private function requiredStoreId(): string
    {
        $storeId = trim(
            (string) $this->request->getPost('store_id')
        );

        if ($storeId === '') {
            throw new InvalidArgumentException(
                'Store ID is required.'
            );
        }

        return $storeId;
    }

    private function executeTransition(
        callable $callback,
        string $successMessage,
        string $errorCode
    ): mixed {
        try {
            return $this->jsonResponder->success($callback());
        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'VALIDATION_ERROR',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                $errorCode,
                $exception->getMessage(),
                400
            );
        }
    }

    public function confirmDeliveryAction(): ResponseInterface
    {
        try {
            $customerId = (string) $this->dispatcher->getParam('userAuthId');

            $vendorOrderId = (string) $this->dispatcher->getParam('id');

            if ($customerId === '' || $vendorOrderId === '') {
                return $this->jsonResponder->error(
                    'INVALID_REQUEST',
                    'Customer identity or vendor order ID is missing.',
                    400
                );
            }

            $vendorOrder = $this->service()->confirmDelivery(
                $customerId,
                $vendorOrderId
            );

            return $this->jsonResponder->success(
                $vendorOrder,
                'Delivery confirmed successfully.'
            );

        } catch (InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'INVALID_REQUEST',
                $exception->getMessage(),
                422
            );

        } catch (RuntimeException $exception) {
            return $this->jsonResponder->error(
                'DELIVERY_CONFIRMATION_FAILED',
                $exception->getMessage(),
                409
            );

        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'DELIVERY_CONFIRMATION_FAILED',
                'Unable to confirm delivery at this time.',
                500
            );
        }
    }
}