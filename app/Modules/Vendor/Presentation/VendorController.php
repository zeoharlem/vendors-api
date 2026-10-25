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
            error_log('Vendor profile creation failed: '. $exception->getMessage());

            return $this->jsonResponder->error('INTERNAL_SERVER_ERROR', $exception->getMessage(), 500);
        }
    }

    public function meAction()
    {
        $userId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || $userId === '') {
            return $this->jsonResponder->error('UNAUTHORIZED', 'Authentication is required.', 401, );
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

}