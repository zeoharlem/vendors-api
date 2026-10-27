<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Presentation;

use App\Modules\Vendor\Application\Exceptions\VendorProfileNotFoundException;
use App\Modules\Vendor\Application\Exceptions\VendorProfileNotPendingException;
use App\Modules\Vendor\Application\Services\VendorReviewService;
use App\Modules\Vendor\Presentation\Validators\RejectVendorValidator;
use Phalcon\Mvc\Controller;
use Throwable;

final class AdminVendorController extends Controller
{
    private VendorReviewService $vendorReviewService;

    private RejectVendorValidator $rejectVendorValidator;

    public function initialize(): void
    {
        $this->vendorReviewService = $this->di->getShared('vendorReviewService');
        $this->rejectVendorValidator = $this->di->getShared('rejectVendorValidator');
    }

    public function pendingAction()
    {
        try {
            $limit = (int) ($this->request->getQuery('limit', 'int') ?? 20);
            $offset = (int) ($this->request->getQuery('offset', 'int') ?? 0);

            $vendors = $this->vendorReviewService->listPending(
                limit: $limit,
                offset: $offset,
            );

            return $this->jsonResponder->success(
                array_map(
                    static fn($vendor): array => $vendor->toArray(),
                    $vendors,
                ),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $exception->getMessage(),
                422,
            );
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Failed to list pending vendors: %s',
                $exception->getMessage(),
            ));

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve pending vendors.',
                500,
            );
        }
    }

    public function showAction()
    {
        try {
            $vendorProfileId = (string) $this->dispatcher->getParameter('id');

            $vendor = $this->vendorReviewService->getById($vendorProfileId);

            return $this->jsonResponder->success($vendor->toArray());
        } catch (VendorProfileNotFoundException) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Vendor profile was not found.',
                404,
            );
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Failed to retrieve vendor profile: %s',
                $exception->getMessage(),
            ));

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve vendor profile.',
                500,
            );
        }
    }

    public function approveAction()
    {
        try {
            $vendorProfileId = (string) $this->dispatcher->getParameter('id');
            $reviewerId = (string) $this->dispatcher->getParameter('userAuthId');

            $vendor = $this->vendorReviewService->approve(
                vendorProfileId: $vendorProfileId,
                reviewerId: $reviewerId,
            );

            return $this->jsonResponder->success($vendor->toArray());
        } catch (VendorProfileNotFoundException) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Vendor profile was not found.',
                404,
            );
        } catch (VendorProfileNotPendingException) {
            return $this->jsonResponder->error(
                'CONFLICT',
                'Only pending vendor profiles can be approved.',
                409,
            );
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Failed to approve vendor profile: %s',
                $exception->getMessage(),
            ));

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to approve vendor profile.',
                500,
            );
        }
    }

    public function rejectAction()
    {
        try {
            $vendorProfileId = (string) $this->dispatcher->getParameter('id');
            $reviewerId = (string) $this->dispatcher->getParameter('userAuthId');

            $body = $this->request->getJsonRawBody(true);

            if (!is_array($body)) {
                return $this->jsonResponder->error(
                    'UNPROCESSABLE_ENTITY',
                    'A valid JSON request body is required.',
                    422,
                );
            }

            $validated = $this->rejectVendorValidator->validate($body);

            $vendor = $this->vendorReviewService->reject(
                vendorProfileId: $vendorProfileId,
                reviewerId: $reviewerId,
                reason: $validated['reason'],
            );

            return $this->jsonResponder->success($vendor->toArray());
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $exception->getMessage(),
                422,
            );
        } catch (VendorProfileNotFoundException) {
            return $this->jsonResponder->error(
                'NOT_FOUND',
                'Vendor profile was not found.',
                404,
            );
        } catch (VendorProfileNotPendingException) {
            return $this->jsonResponder->error(
                'CONFLICT',
                'Only pending vendor profiles can be rejected.',
                409,
            );
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Failed to reject vendor profile: %s',
                $exception->getMessage(),
            ));

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to reject vendor profile.',
                500,
            );
        }
    }
}