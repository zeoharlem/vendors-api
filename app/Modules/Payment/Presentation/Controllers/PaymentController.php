<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Controllers;

use App\Modules\Payment\Application\Dtos\InitializePaymentDto;
use InvalidArgumentException;
use DomainException;
use Throwable;

final class PaymentController extends \Phalcon\Mvc\Controller
{
    public function initializeAction(): mixed
    {
        try {
            $customerId = (string) $this->dispatcher->getParameter('userAuthId');

            if ($customerId === '') {
                return $this->jsonResponder->error(
                    'UNAUTHORIZED',
                    'Authentication is required.',
                    401
                );
            }

            $payload = $this->request->getJsonRawBody(true);

            if (!is_array($payload)) {
                return $this->jsonResponder->error(
                    'UNPROCESSABLE_ENTITY',
                    'A valid JSON request body is required.',
                    422
                );
            }

            $dto = InitializePaymentDto::fromArray($payload);

            $paymentService = $this->di->getShared('paymentService');

            $payment = $paymentService->initialize(customerId: $customerId, dto: $dto);

            return $this->jsonResponder->success($payment);
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
                'Payment initialization failed: ' . $exception->getMessage()
            );

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to initialize payment at this time.',
                500
            );
        }
    }

    public function showAction(): mixed
    {
        try {
            $customerId = (string) $this->dispatcher->getParameter('userAuthId');

            if ($customerId === '') {
                return $this->jsonResponder->error(
                    'UNAUTHORIZED',
                    'Authentication is required.',
                    401
                );
            }

            $reference = trim((string) $this->dispatcher->getParameter('reference'));

            if ($reference === '') {

                return $this->jsonResponder->error(
                    'UNPROCESSABLE_ENTITY',
                    'Payment reference is required.',
                    422
                );
            }

            $paymentService = $this->di->getShared('paymentService');

            $payment = $paymentService->getPayment(customerId: $customerId, reference: $reference);

            if ($payment === null) {

                return $this->jsonResponder->error(
                    'NOT_FOUND',
                    'Payment not found.',
                    404
                );
            }

            return $this->jsonResponder->success($payment);
        } catch (Throwable $exception) {
            error_log(
                'Payment retrieval failed: ' . $exception->getMessage()
            );

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve payment details.',
                500
            );
        }
    }

    /**
     * Verify a payment.
     *
     * @return mixed
     */
    public function verifyAction(): mixed
    {
        $customerId = (string) $this->dispatcher->getParameter('userAuthId');

        $reference = (string) $this->dispatcher->getParameter('reference', '');

        if ($reference === '') {
            return $this->showAction();
        }

        $paymentService = $this->di->getShared('paymentService');

        // Ensure the payment belongs to the authenticated customer.
        $payment = $paymentService->getPayment($customerId, $reference);

        if ($payment === null) {
            return $this->showAction();
        }

        // Reuse the existing verification and payment lifecycle.
        $paymentService->verify($reference);

        // Return the refreshed payment using the existing response format.
        return $this->showAction();
    }
}