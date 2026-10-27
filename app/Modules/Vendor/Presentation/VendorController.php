<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Presentation;

use App\Modules\Vendor\Application\Exceptions\VendorProfileAlreadyExistsException;
use App\Modules\Vendor\Application\Services\VendorProfileService;
use App\Modules\Vendor\Presentation\Validators\CreateVendorProfileValidator;
use InvalidArgumentException;
use Phalcon\Mvc\Controller;
use Throwable;

final class VendorController extends Controller
{

    public function createProfileAction()
    {
        $body = $this->request->getJsonRawBody(true);
        $validation = CreateVendorProfileValidator::validate($body);

        if (!$validation['valid']) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                'Validation failed.' . implode(",", $validation['errors']),
                422
            );
        }

        $userId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || $userId === '') {
            return $this->jsonResponder->error('UNAUTHORIZED', 'Authentication is required.', 401);
        }

        try {
            /** @var VendorProfileService $service */
            $service = $this->vendorProfileService;

            $profile = $service->create(
                $userId,
                $body['business_name'],
                $body['business_registration_number'] ?? null,
            );

            return $this->jsonResponder->success($profile, 201);
        } catch (VendorProfileAlreadyExistsException $exception) {

            return $this->jsonResponder->error('CONFLICT', $exception->getMessage(), 409);

        } catch (InvalidArgumentException $exception) {

            return $this->jsonResponder->error('UNPROCESSABLE_ENTITY', $exception->getMessage(), 422);

        } catch (Throwable $exception) {
            error_log('Vendor profile creation failed: ' . $exception->getMessage());

            return $this->jsonResponder->error('INTERNAL_SERVER_ERROR', $exception->getMessage(), 500);
        }
    }

    public function meAction()
    {
        $userId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || $userId === '') {
            return $this->jsonResponder->error('UNAUTHORIZED', 'Authentication is required.', 401);
        }

        try {
            /** @var VendorProfileService $service */
            $service = $this->getDI()->getShared('vendorProfileService');
            $profile = $service->getForUser($userId);

            if ($profile === null) {
                return $this->jsonResponder->error('NOT_FOUND', 'Vendor profile not found.', 404);
            }

            return $this->jsonResponder->success($profile);
        } catch (Throwable $exception) {
            error_log('Vendor profile retrieval failed: ' . $exception->getMessage());

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve vendor profile.',
                500
            );
        }
    }

    public function updateProfileAction(): mixed
    {

        $userId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || $userId === '') {
            return $this->jsonResponder->error('UNAUTHORIZED', 'Unauthenticated user.', 401);
        }

        $body = $this->request->getJsonRawBody(true);

        if (!is_array($body)) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                'A valid JSON request body is required.',
                422,
            );
        }

        $businessName = $body['business_name'] ?? null;
        $registrationNumber = $body['business_registration_number'] ?? null;

        if (
            !is_string($businessName)
            || (
                $registrationNumber !== null
                && !is_string($registrationNumber)
            )
        ) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                'Invalid business profile fields.',
                422,
            );
        }

        try {
            $profile = $this->getDI()
                ->getShared('vendorProfileService')
                ->updateForUser($userId, $businessName, $registrationNumber);

            if ($profile === null) {
                return $this->jsonResponder->error('NOT_FOUND', 'Vendor profile not found.', 404);
            }

            return $this->jsonResponder->success($profile);
        } catch (InvalidArgumentException $exception) {

            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $exception->getMessage(),
                422,
            );
        } catch (Throwable $exception) {
            error_log('Vendor profile update failed: ' . $exception->getMessage());

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to update vendor profile.',
                500,
            );
        }

    }

}