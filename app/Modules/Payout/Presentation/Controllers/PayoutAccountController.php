<?php

declare(strict_types=1);

namespace App\Modules\Payout\Presentation\Controllers;

use App\Modules\Payout\Application\Services\PayoutAccountService;
use App\Modules\Payout\Domain\Exceptions\PayoutAccountAccessDeniedException;
use App\Shared\Http\AuthenticationRequiredException;
use DomainException;
use Phalcon\Mvc\Controller;
use Throwable;

final class PayoutAccountController extends Controller
{
    public function listBanksAction(): mixed
    {
        try {
            $banks = $this->payoutAccountService()->listBanks();
            
            return $this->jsonResponder->success($banks);
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'bank_list_failed',
                'Unable to retrieve the bank list.',
                502
            );
        }
    }

    public function listAccountsAction(string $storeId): mixed
    {
        try {
            $accounts = $this->payoutAccountService()->listAccounts(
                $this->authenticatedUserId(),
                $storeId
            );

            return $this->jsonResponder->success($accounts);
        } catch (PayoutAccountAccessDeniedException $exception) {
            return $this->jsonResponder->error(
                'forbidden',
                $exception->getMessage(),
                403
            );
        } catch (DomainException $exception) {
            return $this->jsonResponder->error(
                'payout_account_error',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'payout_account_list_failed',
                'Unable to retrieve payout accounts.',
                500
            );
        }
    }

    public function registerAccountAction(string $storeId): mixed
    {
        try {
            $body = $this->request->getJsonRawBody(true);

            if (!is_array($body)) {
                return $this->jsonResponder->error(
                    'invalid_request',
                    'A valid JSON request body is required.',
                    400
                );
            }

            $accountNumber = trim(
                (string) ($body['account_number'] ?? '')
            );

            $bankCode = trim(
                (string) ($body['bank_code'] ?? '')
            );

            $makeDefault = filter_var(
                $body['make_default'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            $account = $this->payoutAccountService()->registerAccount(
                userId: $this->authenticatedUserId(),
                storeId: $storeId,
                accountNumber: $accountNumber,
                bankCode: $bankCode,
                makeDefault: $makeDefault
            );

            return $this->jsonResponder->success($account, 201);
        } catch (PayoutAccountAccessDeniedException $exception) {
            return $this->jsonResponder->error(
                'forbidden',
                $exception->getMessage(),
                403
            );
        } catch (DomainException $exception) {
            return $this->jsonResponder->error(
                'payout_account_error',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'payout_account_registration_failed',
                'Unable to register the payout account.'. $exception->getMessage(),
                500
            );
        }
    }

    public function setDefaultAction(
        string $storeId,
        string $accountId
    ): mixed {
        try {
            $account = $this->payoutAccountService()->setDefault(
                userId: $this->authenticatedUserId(),
                storeId: $storeId,
                accountId: $accountId
            );

            return $this->jsonResponder->success($account);
        } catch (PayoutAccountAccessDeniedException $exception) {
            return $this->jsonResponder->error(
                'forbidden',
                $exception->getMessage(),
                403
            );
        } catch (DomainException $exception) {
            return $this->jsonResponder->error(
                'payout_account_error',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'payout_account_update_failed',
                'Unable to update the default payout account.',
                500
            );
        }
    }

    public function disableAccountAction(
        string $storeId,
        string $accountId
    ): mixed {
        try {
            $this->payoutAccountService()->disableAccount(
                userId: $this->authenticatedUserId(),
                storeId: $storeId,
                accountId: $accountId
            );

            return $this->jsonResponder->success([
                'account_id' => $accountId,
                'status' => 'disabled',
            ]);
        } catch (PayoutAccountAccessDeniedException $exception) {
            return $this->jsonResponder->error(
                'forbidden',
                $exception->getMessage(),
                403
            );
        } catch (DomainException $exception) {
            return $this->jsonResponder->error(
                'payout_account_error',
                $exception->getMessage(),
                422
            );
        } catch (Throwable $exception) {
            return $this->jsonResponder->error(
                'payout_account_disable_failed',
                'Unable to disable the payout account.',
                500
            );
        }
    }

    private function authenticatedUserId(): string
    {
        $userId = $this->dispatcher->getParameter('userAuthId');

        if (!is_string($userId) || trim($userId) === '') {
            throw new AuthenticationRequiredException(
                'Authentication is required.'
            );
        }

        return $userId;
    }

    private function payoutAccountService(): PayoutAccountService
    {
        return $this->di->getShared(PayoutAccountService::class);
    }
}
