<?php

declare(strict_types=1);

namespace App\Modules\Payout\Presentation\Controllers;

use App\Modules\Payout\Application\Services\PayoutService;
use App\Modules\Payout\Domain\Exceptions\PayoutAccessDeniedException;
use App\Shared\Http\AuthenticationRequiredException;
use DomainException;
use Phalcon\Mvc\Controller;
use Throwable;

final class PayoutController extends Controller
{
    public function requestAction(string $storeId)
    {
        $userId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || $userId === '') {
            throw new AuthenticationRequiredException(
                'Authentication is required.'
            );
        }

        $payload = $this->request->getJsonRawBody(true);

        if (!is_array($payload)) {
            return $this->jsonResponder->error(
                'BAD_REQUEST',
                'A valid JSON request body is required.',
                400
            );
        }

        $vendorPayableId = trim((string) ($payload['vendor_payable_id'] ?? ''));
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        $payoutAccountId = isset($payload['payout_account_id'])
            ? trim((string) $payload['payout_account_id'])
            : null;

        if ($vendorPayableId === '' || $idempotencyKey === '') {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                'vendor_payable_id and idempotency_key are required.',
                422
            );
        }

        try {
            $result = $this->di->getShared(PayoutService::class)->requestPayout(
                userId: $userId,
                storeId: $storeId,
                vendorPayableId: $vendorPayableId,
                idempotencyKey: $idempotencyKey,
                payoutAccountId: $payoutAccountId !== '' ? $payoutAccountId : null
            );

            return $this->jsonResponder->success($result, 202);
        } catch (PayoutAccessDeniedException $exception) {
            return $this->jsonResponder->error(
                'FORBIDDEN',
                $exception->getMessage(),
                403
            );
        } catch (DomainException $exception) {
            return $this->jsonResponder->error(
                'UNPROCESSABLE_ENTITY',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Payout request failed. store_id=%s vendor_payable_id=%s exception=%s',
                $storeId,
                $vendorPayableId,
                $exception->getMessage()
            ));

            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to process the payout request.' . $exception->getMessage(),
                500
            );
        }
    }
}