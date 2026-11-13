<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Services;

use App\Modules\Ledger\Domain\Contracts\CommissionCalculatorInterface;
use App\Modules\Ledger\Domain\Repositories\LedgerRepository;
use App\Modules\Ledger\Domain\Repositories\VendorPayableRepository;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final class LedgerPostingService
{
    private const CURRENCY = 'NGN';

    public function __construct(
        private readonly LedgerRepository $ledgerRepository,
        private readonly VendorPayableRepository $vendorPayableRepository,
        private readonly CommissionCalculatorInterface $commissionCalculator,
        private readonly int $defaultCommissionRateBps
    ) {
        if (
            $defaultCommissionRateBps < 0
            || $defaultCommissionRateBps > 10000
        ) {
            throw new InvalidArgumentException(
                'Default commission rate must be between 0 and 10000 basis points.'
            );
        }
    }

    /**
     * Post the financial allocation for every vendor order belonging
     * to a successfully confirmed customer payment.
     *
     * This method must execute inside the existing payment database
     * transaction. It must not begin, commit, or roll back transactions.
     *
     * Each vendor order gets its own idempotent ledger transaction.
     */
    public function postPayment(array $payment, array $vendorOrders): array
    {
        $paymentId = $payment['id'] ?? null;
        $orderId = $payment['order_id'] ?? null;
        $paymentAmount = (int) ($payment['amount_minor'] ?? 0);
        $currency = strtoupper(
            (string) ($payment['currency'] ?? self::CURRENCY)
        );

        if (!$paymentId || !$orderId) {
            throw new InvalidArgumentException(
                'Payment ID and order ID are required for ledger posting.'
            );
        }

        if ($paymentAmount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if ($currency !== self::CURRENCY) {
            throw new InvalidArgumentException('Unsupported ledger currency.');
        }

        if ($vendorOrders === []) {
            throw new RuntimeException('No vendor orders were supplied for ledger posting.');
        }

        /*
         * Validate the entire payment allocation before creating
         * any ledger transaction.
         */
        $vendorOrderTotal = 0;

        foreach ($vendorOrders as $vendorOrder) {
            $vendorOrderTotal += $this->amount($vendorOrder, 'total_minor');

            $vendorCurrency = strtoupper((string) ($vendorOrder['currency'] ?? $currency));

            if ($vendorCurrency !== $currency) {
                throw new RuntimeException(
                    'Vendor order currency does not match payment currency.'
                );
            }
        }

        if ($vendorOrderTotal !== $paymentAmount) {
            throw new RuntimeException(
                'Vendor order totals do not match the confirmed payment amount.'
            );
        }

        $postedVendorOrders = [];

        foreach ($vendorOrders as $vendorOrder) {
            $postedVendorOrders[] = $this->postVendorOrder($payment, $vendorOrder, $currency);
        }

        return [
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'amount_minor' => $paymentAmount,
            'currency' => $currency,
            'vendor_orders' => $postedVendorOrders,
        ];
    }

    /**
     * Post the financial settlement of a vendor payable.
     *
     * The gross payable is settled through:
     * - Actual transfer to the vendor.
     * - Recovery of outstanding vendor debt.
     *
     * This method must execute inside the caller's existing database
     * transaction. It must not begin, commit, or roll back transactions.
     *
     * @return array{
     *     payout_id: string,
     *     ledger_transaction_id: string,
     *     gross_amount_minor: int,
     *     transfer_amount_minor: int,
     *     debt_recovered_minor: int,
     *     status: string
     * }
     */
    public function postVendorPayout(
        array $payout,
        array $vendorPayable
    ): array {
        $payoutId = $payout['id'] ?? null;
        $vendorPayableId = $payout['vendor_payable_id'] ?? null;
        $storeId = $payout['store_id'] ?? null;
        $vendorOrderId = $payout['vendor_order_id']
            ?? $vendorPayable['vendor_order_id']
            ?? null;

        if (!$payoutId || !$vendorPayableId || !$storeId || !$vendorOrderId) {
            throw new InvalidArgumentException(
                'Payout, vendor payable, store and vendor order IDs are required.'
            );
        }

        $currency = strtoupper(
            (string) ($payout['currency'] ?? self::CURRENCY)
        );

        if ($currency !== self::CURRENCY) {
            throw new InvalidArgumentException(
                'Unsupported payout ledger currency.'
            );
        }

        $grossMinor = $this->amount($payout, 'gross_amount_minor');
        $transferMinor = $this->amount($payout, 'amount_minor');
        $debtRecoveredMinor = $this->amount(
            $payout,
            'debt_recovered_minor'
        );

        if ($grossMinor <= 0) {
            throw new InvalidArgumentException(
                'Gross payout amount must be greater than zero.'
            );
        }

        if (($transferMinor + $debtRecoveredMinor) !== $grossMinor) {
            throw new RuntimeException(
                'Payout transfer and debt recovery do not reconcile with gross payable.'
            );
        }

        if ($transferMinor === 0 && $debtRecoveredMinor === 0) {
            throw new RuntimeException(
                'A payout cannot settle without a transfer or debt recovery.'
            );
        }

        $payableCurrency = strtoupper(
            (string) ($vendorPayable['currency'] ?? $currency)
        );

        if ($payableCurrency !== $currency) {
            throw new RuntimeException(
                'Payout currency does not match vendor payable currency.'
            );
        }

        $transactionKey = sprintf(
            'payout:%s:settlement',
            $payoutId
        );

        // Idempotency: a posted payout must never be posted twice.
        $existingTransaction = $this->ledgerRepository
            ->findTransactionByKey($transactionKey);

        if ($existingTransaction) {
            if (($existingTransaction['status'] ?? null) === 'posted') {
                return [
                    'payout_id' => $payoutId,
                    'ledger_transaction_id' => $existingTransaction['id'],
                    'gross_amount_minor' => $grossMinor,
                    'transfer_amount_minor' => $transferMinor,
                    'debt_recovered_minor' => $debtRecoveredMinor,
                    'status' => 'already_posted',
                ];
            }

            throw new RuntimeException(
                "Payout ledger transaction {$transactionKey} already exists "
                . 'without a completed posting. Manual reconciliation is required.'
            );
        }

        // Debit the vendor payable liability.
        $vendorPayableAccount = $this->account(
            'VENDOR_PAYABLE_' . strtoupper((string) $storeId),
            'Vendor Payable - ' . $storeId,
            'liability',
            $currency,
            null,
            (string) $storeId
        );

        // Credit Paystack clearing for the actual amount transferred.
        $clearingAccount = $this->account(
            'PAYSTACK_CLEARING',
            'Paystack Clearing',
            'asset',
            $currency
        );

        // Credit vendor receivable to reduce the outstanding debt asset.
        $vendorReceivableAccount = $this->account(
            'VENDOR_RECEIVABLE_' . strtoupper((string) $storeId),
            'Vendor Receivable - ' . $storeId,
            'asset',
            $currency,
            null,
            (string) $storeId
        );

        $transaction = $this->ledgerRepository->createTransaction(
            [
                'transaction_key' => $transactionKey,
                'transaction_type' => 'vendor_payout_settlement',
                'order_id' => $vendorPayable['order_id'] ?? null,
                'payment_id' => null,
                'vendor_order_id' => $vendorOrderId,
                'payout_id' => $payoutId,
                'currency' => $currency,
                'description' => 'Vendor payout settlement',
                'metadata' => [
                    'payout_id' => $payoutId,
                    'vendor_payable_id' => $vendorPayableId,
                    'store_id' => $storeId,
                    'gross_amount_minor' => $grossMinor,
                    'transfer_amount_minor' => $transferMinor,
                    'debt_recovered_minor' => $debtRecoveredMinor,
                    'provider' => $payout['provider'] ?? null,
                    'provider_reference' => $payout['provider_reference'] ?? null,
                ],
            ]
        );

        if (($transaction['status'] ?? null) !== 'pending') {
            throw new RuntimeException(
                'New payout ledger transaction is not in pending status.'
            );
        }

        $transactionId = $transaction['id'];

        // DEBIT: reduce the vendor payable liability by the full gross amount.
        $this->ledgerRepository->createEntry([
            'ledger_transaction_id' => $transactionId,
            'ledger_account_id' => $vendorPayableAccount['id'],
            'entry_side' => 'D',
            'amount_minor' => $grossMinor,
            'currency' => $currency,
            'description' => 'Vendor payable settled',
        ]);

        // CREDIT: actual funds transferred to the vendor.
        if ($transferMinor > 0) {
            $this->ledgerRepository->createEntry([
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $clearingAccount['id'],
                'entry_side' => 'C',
                'amount_minor' => $transferMinor,
                'currency' => $currency,
                'description' => 'Vendor payout transfer',
            ]);
        }

        // CREDIT: recover the vendor receivable created by refund settlement.
        if ($debtRecoveredMinor > 0) {
            $this->ledgerRepository->createEntry([
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $vendorReceivableAccount['id'],
                'entry_side' => 'C',
                'amount_minor' => $debtRecoveredMinor,
                'currency' => $currency,
                'description' => 'Vendor refund debt recovered from payout',
            ]);
        }

        // Verify that debits and credits balance.
        $this->ledgerRepository->markTransactionPosted($transactionId);

        return [
            'payout_id' => $payoutId,
            'ledger_transaction_id' => $transactionId,
            'gross_amount_minor' => $grossMinor,
            'transfer_amount_minor' => $transferMinor,
            'debt_recovered_minor' => $debtRecoveredMinor,
            'status' => 'posted',
        ];
    }

    private function postVendorOrder(array $payment, array $vendorOrder, string $currency): array
    {
        $vendorOrderId = $vendorOrder['id'] ?? null;
        $storeId = $vendorOrder['store_id'] ?? null;

        if (!$vendorOrderId || !$storeId) {
            throw new InvalidArgumentException(
                'Vendor order ID and store ID are required.'
            );
        }

        $subtotalMinor = $this->amount(
            $vendorOrder,
            'subtotal_minor'
        );

        $discountMinor = $this->amount(
            $vendorOrder,
            'discount_minor'
        );

        $shippingMinor = $this->amount(
            $vendorOrder,
            'shipping_minor'
        );

        $taxMinor = $this->amount(
            $vendorOrder,
            'tax_minor'
        );

        $totalMinor = $this->amount(
            $vendorOrder,
            'total_minor'
        );

        if ($discountMinor > $subtotalMinor) {
            throw new RuntimeException(
                'Vendor order discount cannot exceed merchandise subtotal.'
            );
        }

        $merchandiseNetMinor = $subtotalMinor - $discountMinor;

        $expectedTotal = $merchandiseNetMinor + $shippingMinor + $taxMinor;

        if ($expectedTotal !== $totalMinor) {
            throw new RuntimeException(
                "Vendor order {$vendorOrderId} financial breakdown "
                . 'does not reconcile with its total.'
            );
        }

        /*
         * NULL means use the platform default.
         * A value of zero is a valid store-specific override.
         */
        $commissionRateBps = isset($vendorOrder['commission_rate_bps'])
            ? (int) $vendorOrder['commission_rate_bps']
            : $this->defaultCommissionRateBps;

        if ($commissionRateBps < 0 || $commissionRateBps > 10000) {
            throw new RuntimeException(
                "Invalid commission rate for store {$storeId}."
            );
        }

        $commissionMinor = $this->commissionCalculator->calculate(
            $merchandiseNetMinor,
            $commissionRateBps
        );

        $vendorPayableMinor = $merchandiseNetMinor - $commissionMinor;

        $transactionKey = sprintf(
            'payment:%s:vendor-order:%s:allocation',
            $payment['id'],
            $vendorOrderId
        );

        /*
         * Check idempotency before creating entries.
         *
         * A previously posted transaction must never be posted again.
         * A pending transaction indicates an incomplete posting and
         * should not silently receive duplicate entries.
         */
        $existingTransaction = $this->ledgerRepository
            ->findTransactionByKey($transactionKey);

        if ($existingTransaction) {
            $status = $existingTransaction['status'] ?? null;

            if ($status === 'posted') {
                $vendorPayable = $this->vendorPayableRepository->createHeldIfNotExists(
                    [
                        'vendor_order_id' => $vendorOrderId,
                        'store_id' => $storeId,
                        'currency' => $currency,
                        'gross_minor' => $merchandiseNetMinor,
                        'commission_minor' => $commissionMinor,
                        'payable_minor' => $vendorPayableMinor,
                    ]
                );

                return [
                    'vendor_order_id' => $vendorOrderId,
                    'ledger_transaction_id' => $existingTransaction['id'],
                    'vendor_payable_id' => $vendorPayable['id'],
                    'status' => 'already_posted',
                ];
            }

            throw new RuntimeException(
                "Ledger transaction {$transactionKey} already exists "
                . "with status {$status}. Manual reconciliation is required."
            );
        }

        /*
         * Resolve the accounts required for this allocation.
         */
        $clearingAccount = $this->account(
            'PAYSTACK_CLEARING',
            'Paystack Clearing',
            'asset',
            $currency
        );

        $commissionAccount = $this->account(
            'PLATFORM_COMMISSION',
            'Platform Commission Revenue',
            'revenue',
            $currency
        );

        $shippingAccount = $this->account(
            'SHIPPING_PAYABLE',
            'Shipping Payable',
            'liability',
            $currency
        );

        $taxAccount = $this->account(
            'TAX_PAYABLE',
            'Tax Payable',
            'liability',
            $currency
        );

        $vendorPayableAccount = $this->account(
            'VENDOR_PAYABLE_' . strtoupper((string) $storeId),
            'Vendor Payable - ' . $storeId,
            'liability',
            $currency,
            null,
            (string) $storeId
        );

        /*
         * Create the ledger transaction header.
         */
        $transaction = $this->ledgerRepository->createTransaction(
            [
                'transaction_key' => $transactionKey,
                'transaction_type' => 'order_payment_allocation',
                'order_id' => $payment['order_id'],
                'payment_id' => $payment['id'],
                'vendor_order_id' => $vendorOrderId,
                'currency' => $currency,
                'description' => 'Payment allocation for vendor order '
                    . ($vendorOrder['order_number'] ?? $vendorOrderId),
                'metadata' => [
                    'payment_reference' => $payment['reference'] ?? null,
                    'vendor_order_id' => $vendorOrderId,
                    'store_id' => $storeId,
                    'subtotal_minor' => $subtotalMinor,
                    'discount_minor' => $discountMinor,
                    'merchandise_net_minor' => $merchandiseNetMinor,
                    'commission_rate_bps' => $commissionRateBps,
                    'commission_minor' => $commissionMinor,
                    'vendor_payable_minor' => $vendorPayableMinor,
                    'shipping_minor' => $shippingMinor,
                    'tax_minor' => $taxMinor,
                ],
            ]
        );

        if (($transaction['status'] ?? null) !== 'pending') {
            throw new RuntimeException(
                'New ledger transaction is not in pending status.'
            );
        }

        $transactionId = $transaction['id'];

        /*
         * DEBIT: Paystack clearing asset.
         */
        $this->ledgerRepository->createEntry(
            [
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $clearingAccount['id'],
                'entry_side' => 'D',
                'amount_minor' => $totalMinor,
                'currency' => $currency,
                'description' => 'Customer payment received',
            ]
        );

        /*
         * CREDIT: Vendor payable liability.
         */
        if ($vendorPayableMinor > 0) {
            $this->ledgerRepository->createEntry(
                [
                    'ledger_transaction_id' => $transactionId,
                    'ledger_account_id' => $vendorPayableAccount['id'],
                    'entry_side' => 'C',
                    'amount_minor' => $vendorPayableMinor,
                    'currency' => $currency,
                    'description' => 'Vendor merchandise payable',
                ]
            );
        }

        /*
         * CREDIT: Platform commission revenue.
         */
        if ($commissionMinor > 0) {
            $this->ledgerRepository->createEntry(
                [
                    'ledger_transaction_id' => $transactionId,
                    'ledger_account_id' => $commissionAccount['id'],
                    'entry_side' => 'C',
                    'amount_minor' => $commissionMinor,
                    'currency' => $currency,
                    'description' => 'Platform commission',
                ]
            );
        }

        /*
         * CREDIT: Shipping payable liability.
         */
        if ($shippingMinor > 0) {
            $this->ledgerRepository->createEntry(
                [
                    'ledger_transaction_id' => $transactionId,
                    'ledger_account_id' => $shippingAccount['id'],
                    'entry_side' => 'C',
                    'amount_minor' => $shippingMinor,
                    'currency' => $currency,
                    'description' => 'Shipping amount payable',
                ]
            );
        }

        /*
         * CREDIT: Tax payable liability.
         */
        if ($taxMinor > 0) {
            $this->ledgerRepository->createEntry(
                [
                    'ledger_transaction_id' => $transactionId,
                    'ledger_account_id' => $taxAccount['id'],
                    'entry_side' => 'C',
                    'amount_minor' => $taxMinor,
                    'currency' => $currency,
                    'description' => 'Tax payable',
                ]
            );
        }

        /*
         * This method verifies that debit and credit totals balance.
         */
        $this->ledgerRepository->markTransactionPosted($transactionId);

        $vendorPayable = $this->vendorPayableRepository->createHeldIfNotExists([
            'vendor_order_id' => $vendorOrderId,
            'store_id' => $storeId,
            'currency' => $currency,
            'gross_minor' => $merchandiseNetMinor,
            'commission_minor' => $commissionMinor,
            'payable_minor' => $vendorPayableMinor,
        ]);

        return [
            'vendor_order_id' => $vendorOrderId,
            'ledger_transaction_id' => $transactionId,
            'vendor_payable_id' => $vendorPayable['id'],
            'status' => 'posted',
            'total_minor' => $totalMinor,
            'commission_minor' => $commissionMinor,
            'vendor_payable_minor' => $vendorPayableMinor,
        ];
    }

    public function reverseVendorPayout(
        array $payout,
        array $vendorPayable
    ): array {
        $payoutId = (string) ($payout['id'] ?? '');
        $storeId = (string) ($payout['store_id'] ?? '');
        $vendorOrderId = (string) ($payout['vendor_order_id'] ?? '');
        $transferAmount = (int) ($payout['amount_minor'] ?? 0);
        $currency = strtoupper((string) ($payout['currency'] ?? ''));

        if (
            $payoutId === ''
            || $storeId === ''
            || $vendorOrderId === ''
            || $transferAmount <= 0
            || $currency !== 'NGN'
        ) {
            throw new DomainException(
                'Invalid payout reversal data.'
            );
        }

        $transactionKey = 'payout:' . $payoutId . ':reversal';

        $existing = $this->ledgerRepository->findTransactionByKey(
            $transactionKey
        );

        if ($existing !== null) {
            if (($existing['status'] ?? '') === 'posted') {
                return [
                    'payout_id' => $payoutId,
                    'ledger_transaction_id' => $existing['id'],
                    'amount_minor' => $transferAmount,
                    'status' => 'already_reversed',
                ];
            }

            throw new DomainException(
                'A payout reversal ledger transaction is already pending.'
            );
        }

        $storeSuffix = strtoupper(str_replace('-', '', $storeId));

        $clearing = $this->ledgerRepository->findAccountByCode(
            'PAYSTACK_CLEARING'
        );

        $vendorPayableAccount = $this->ledgerRepository->findAccountByCode(
            'VENDOR_PAYABLE_' . $storeSuffix
        );

        if ($clearing === null || $vendorPayableAccount === null) {
            throw new DomainException(
                'Required payout reversal ledger accounts were not found.'
            );
        }

        $transaction = $this->ledgerRepository->createTransaction([
            'transaction_key' => $transactionKey,
            'transaction_type' => 'vendor_payout_reversal',
            'order_id' => $vendorPayable['order_id'] ?? null,
            'payment_id' => null,
            'vendor_order_id' => $vendorOrderId,
            'payout_id' => $payoutId,
            'currency' => $currency,
            'description' => 'Reversal of vendor payout transfer',
            'metadata' => [
                'payout_id' => $payoutId,
                'vendor_payable_id' => $payout['vendor_payable_id'],
                'store_id' => $storeId,
                'transfer_amount_minor' => $transferAmount,
                'provider_reference' => $payout['provider_reference'] ?? null,
            ],
        ]);

        $transactionId = (string) $transaction['id'];

        if (($transaction['status'] ?? '') !== 'pending') {
            throw new DomainException(
                'Payout reversal ledger transaction is not pending.'
            );
        }

        $this->ledgerRepository->createEntry([
            'ledger_transaction_id' => $transactionId,
            'ledger_account_id' => $clearing['id'],
            'entry_side' => 'D',
            'amount_minor' => $transferAmount,
            'currency' => $currency,
            'description' => 'Paystack payout transfer reversal',
        ]);

        $this->ledgerRepository->createEntry([
            'ledger_transaction_id' => $transactionId,
            'ledger_account_id' => $vendorPayableAccount['id'],
            'entry_side' => 'C',
            'amount_minor' => $transferAmount,
            'currency' => $currency,
            'description' => 'Restore vendor payable after payout reversal',
        ]);

        $this->ledgerRepository->markTransactionPosted($transactionId);

        return [
            'payout_id' => $payoutId,
            'ledger_transaction_id' => $transactionId,
            'amount_minor' => $transferAmount,
            'status' => 'reversed',
        ];
    }

    private function account(
        string $code,
        string $name,
        string $accountType,
        string $currency,
        ?string $ownerUserId = null,
        ?string $storeId = null
    ): array {
        return $this->ledgerRepository->createAccountIfNotExists(
            [
                'code' => $code,
                'name' => $name,
                'account_type' => $accountType,
                'currency' => $currency,
                'owner_user_id' => $ownerUserId,
                'store_id' => $storeId,
                'is_active' => true,
            ]
        );
    }

    private function amount(array $data, string $field): int
    {
        if (!array_key_exists($field, $data)) {
            throw new InvalidArgumentException(
                "Missing financial field: {$field}"
            );
        }

        if (!is_numeric($data[$field])) {
            throw new InvalidArgumentException(
                "Invalid financial amount: {$field}"
            );
        }

        $amount = (int) $data[$field];

        if ($amount < 0) {
            throw new InvalidArgumentException(
                "Financial amount cannot be negative: {$field}"
            );
        }

        return $amount;
    }
}