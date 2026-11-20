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

    /**
     * Allocate a refund proportionally across the original payment components.
     *
     * The original payment allocation is the source of truth. Commission rates
     * are never recalculated during a refund.
     *
     * @return array{
     *     vendor_payable_minor: int,
     *     commission_minor: int,
     *     shipping_minor: int,
     *     tax_minor: int
     * }
     */
    private function allocateRefundComponents(
        int $refundAmountMinor,
        array $originalAllocation
    ): array {
        if ($refundAmountMinor <= 0) {
            throw new InvalidArgumentException(
                'Refund amount must be greater than zero.'
            );
        }

        $components = [
            'vendor_payable_minor' => $this->amount(
                $originalAllocation['vendor_payable_minor'] ?? 0,
                'Original vendor payable'
            ),
            'commission_minor' => $this->amount(
                $originalAllocation['commission_minor'] ?? 0,
                'Original commission'
            ),
            'shipping_minor' => $this->amount(
                $originalAllocation['shipping_minor'] ?? 0,
                'Original shipping'
            ),
            'tax_minor' => $this->amount(
                $originalAllocation['tax_minor'] ?? 0,
                'Original tax'
            ),
        ];

        $originalTotal = array_sum($components);

        if ($originalTotal <= 0) {
            throw new RuntimeException(
                'Original payment allocation is invalid.'
            );
        }

        if ($refundAmountMinor > $originalTotal) {
            throw new InvalidArgumentException(
                'Refund amount exceeds the original allocation.'
            );
        }

        if ($refundAmountMinor === $originalTotal) {
            return $components;
        }

        $allocated = [];
        $remainders = [];
        $allocatedTotal = 0;

        foreach ($components as $key => $componentAmount) {
            $numerator = $refundAmountMinor * $componentAmount;

            $share = intdiv($numerator, $originalTotal);

            $allocated[$key] = $share;
            $allocatedTotal += $share;

            $remainders[$key] = $numerator % $originalTotal;
        }

        // Distribute rounding remainder using the largest fractional shares.
        arsort($remainders);

        $remaining = $refundAmountMinor - $allocatedTotal;

        foreach (array_keys($remainders) as $key) {
            if ($remaining <= 0) {
                break;
            }

            if ($allocated[$key] >= $components[$key]) {
                continue;
            }

            $allocated[$key]++;
            $remaining--;
        }

        if ($remaining !== 0) {
            throw new RuntimeException(
                'Unable to balance refund allocation.'
            );
        }

        if (array_sum($allocated) !== $refundAmountMinor) {
            throw new RuntimeException(
                'Refund allocation does not balance.'
            );
        }

        return $allocated;
    }

    /**
     * Post the accounting entries for a successful customer refund.
     *
     * The original payment allocation is used as the source of truth.
     * The caller must ensure any required vendor payout recovery has
     * already been confirmed and accounted for.
     *
     * This method participates in the caller's existing transaction.
     *
     * @return array<string, mixed>
     */
    public function postRefund(
        array $refund,
        array $originalAllocationTransaction
    ): array {
        $refundId = $refund['id'] ?? null;
        $paymentId = $refund['payment_id'] ?? null;
        $orderId = $refund['order_id'] ?? null;
        $vendorOrderId = $refund['vendor_order_id'] ?? null;

        if (
            !$refundId ||
            !$paymentId ||
            !$orderId ||
            !$vendorOrderId
        ) {
            throw new InvalidArgumentException(
                'Refund, payment, order and vendor order IDs are required.'
            );
        }

        $currency = strtoupper(trim((string) ($refund['currency'] ?? '')));

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(
                'A valid refund currency is required.'
            );
        }

        $refundAmountMinor = $this->amount($refund, 'amount_minor');

        if ($refundAmountMinor <= 0) {
            throw new InvalidArgumentException(
                'Refund amount must be greater than zero.'
            );
        }

        if (
            ($originalAllocationTransaction['status'] ?? null) !== 'posted' ||
            ($originalAllocationTransaction['transaction_type'] ?? null)
            !== 'order_payment_allocation' ||
            ($originalAllocationTransaction['payment_id'] ?? null) !== $paymentId ||
            ($originalAllocationTransaction['vendor_order_id'] ?? null)
            !== $vendorOrderId ||
            strtoupper(trim((string) (
                $originalAllocationTransaction['currency'] ?? ''
            ))) !== $currency
        ) {
            throw new RuntimeException(
                'The original payment allocation transaction is invalid.'
            );
        }

        $metadata = $originalAllocationTransaction['metadata'] ?? [];

        if (is_string($metadata)) {
            try {
                $metadata = json_decode(
                    $metadata,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $exception) {
                throw new RuntimeException(
                    'Original payment allocation metadata is invalid.',
                    previous: $exception
                );
            }
        }

        if (!is_array($metadata)) {
            throw new RuntimeException(
                'Original payment allocation metadata is unavailable.'
            );
        }

        $storeId = $metadata['store_id'] ?? null;

        if (
            !$storeId ||
            ($metadata['vendor_order_id'] ?? null) !== $vendorOrderId
        ) {
            throw new RuntimeException(
                'Original payment allocation does not match the refund.'
            );
        }

        $originalAllocation = [
            'vendor_payable_minor' => $this->amount(
                $metadata,
                'vendor_payable_minor'
            ),
            'commission_minor' => $this->amount(
                $metadata,
                'commission_minor'
            ),
            'shipping_minor' => $this->amount(
                $metadata,
                'shipping_minor'
            ),
            'tax_minor' => $this->amount(
                $metadata,
                'tax_minor'
            ),
        ];

        $originalTotal = array_sum($originalAllocation);

        if ($originalTotal <= 0 || $refundAmountMinor > $originalTotal) {
            throw new InvalidArgumentException(
                'Refund amount exceeds the original vendor-order allocation.'
            );
        }

        $allocation = $this->allocateRefundComponents(
            $refundAmountMinor,
            $originalAllocation
        );

        $transactionKey = sprintf(
            'refund:%s:settlement',
            $refundId
        );

        /*
         * Check idempotency before creating accounts or entries.
         */
        $existingTransaction = $this->ledgerRepository
            ->findTransactionByKey($transactionKey);

        if ($existingTransaction !== null) {
            $matches = (
                ($existingTransaction['status'] ?? null) === 'posted' &&
                ($existingTransaction['transaction_type'] ?? null)
                === 'customer_refund_settlement' &&
                ($existingTransaction['refund_id'] ?? null) === $refundId &&
                ($existingTransaction['payment_id'] ?? null) === $paymentId &&
                ($existingTransaction['vendor_order_id'] ?? null)
                === $vendorOrderId &&
                strtoupper(trim((string) (
                    $existingTransaction['currency'] ?? ''
                ))) === $currency
            );

            if (!$matches) {
                throw new RuntimeException(
                    'An existing refund ledger transaction does not match '
                    . 'the current refund. Manual reconciliation is required.'
                );
            }

            return [
                'refund_id' => $refundId,
                'ledger_transaction_id' => $existingTransaction['id'],
                'status' => 'already_posted',
                'allocation' => $allocation,
            ];
        }

        /*
         * Resolve the accounts.
         */
        $clearingAccount = $this->account(
            'PAYSTACK_CLEARING',
            'Paystack Clearing',
            'asset',
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

        /*
         * Create the refund transaction.
         */
        $transaction = $this->ledgerRepository->createTransaction([
            'transaction_key' => $transactionKey,
            'transaction_type' => 'customer_refund_settlement',
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'vendor_order_id' => $vendorOrderId,
            'refund_id' => $refundId,
            'currency' => $currency,
            'description' => 'Customer refund settlement for vendor order '
                . $vendorOrderId,
            'metadata' => [
                'refund_id' => $refundId,
                'payment_id' => $paymentId,
                'vendor_order_id' => $vendorOrderId,
                'store_id' => $storeId,
                'refund_amount_minor' => $refundAmountMinor,
                'original_allocation_transaction_id'
                => $originalAllocationTransaction['id'],
                'vendor_payable_minor'
                => $allocation['vendor_payable_minor'],
                'commission_minor'
                => $allocation['commission_minor'],
                'shipping_minor'
                => $allocation['shipping_minor'],
                'tax_minor'
                => $allocation['tax_minor'],
            ],
        ]);

        if (($transaction['status'] ?? null) !== 'pending') {
            throw new RuntimeException(
                'New refund ledger transaction is not in pending status.'
            );
        }

        $transactionId = $transaction['id'];

        /*
         * DEBIT: Reverse the original vendor payable allocation.
         */
        if ($allocation['vendor_payable_minor'] > 0) {
            $this->ledgerRepository->createEntry([
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $vendorPayableAccount['id'],
                'entry_side' => 'D',
                'amount_minor' => $allocation['vendor_payable_minor'],
                'currency' => $currency,
                'description' => 'Vendor payable reversed for customer refund',
            ]);
        }

        /*
         * DEBIT: Reverse platform commission.
         */
        if ($allocation['commission_minor'] > 0) {
            $this->ledgerRepository->createEntry([
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $commissionAccount['id'],
                'entry_side' => 'D',
                'amount_minor' => $allocation['commission_minor'],
                'currency' => $currency,
                'description' => 'Platform commission reversed for refund',
            ]);
        }

        /*
         * DEBIT: Reverse shipping payable.
         */
        if ($allocation['shipping_minor'] > 0) {
            $this->ledgerRepository->createEntry([
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $shippingAccount['id'],
                'entry_side' => 'D',
                'amount_minor' => $allocation['shipping_minor'],
                'currency' => $currency,
                'description' => 'Shipping payable reversed for refund',
            ]);
        }

        /*
         * DEBIT: Reverse tax payable.
         */
        if ($allocation['tax_minor'] > 0) {
            $this->ledgerRepository->createEntry([
                'ledger_transaction_id' => $transactionId,
                'ledger_account_id' => $taxAccount['id'],
                'entry_side' => 'D',
                'amount_minor' => $allocation['tax_minor'],
                'currency' => $currency,
                'description' => 'Tax payable reversed for refund',
            ]);
        }

        /*
         * CREDIT: Paystack clearing for the customer refund.
         */
        $this->ledgerRepository->createEntry([
            'ledger_transaction_id' => $transactionId,
            'ledger_account_id' => $clearingAccount['id'],
            'entry_side' => 'C',
            'amount_minor' => $refundAmountMinor,
            'currency' => $currency,
            'description' => 'Customer refund paid through Paystack',
        ]);

        $this->ledgerRepository->markTransactionPosted($transactionId);

        return [
            'refund_id' => $refundId,
            'ledger_transaction_id' => $transactionId,
            'status' => 'posted',
            'allocation' => $allocation,
        ];
    }

    public function postVendorPayoutRecovery(
        array $recovery,
        array $payout
    ): array {
        $recoveryId = (string) $recovery['id'];
        $payoutId = (string) $payout['id'];
        $payableId = (string) $recovery['vendor_payable_id'];
        $storeId = (string) $recovery['store_id'];
        $currency = strtoupper(trim((string) $recovery['currency']));
        $amount = (int) $recovery['amount_minor'];

        if ($amount <= 0) {
            throw new InvalidArgumentException(
                'Recovery amount must be greater than zero.'
            );
        }

        if (
            (string) $payout['vendor_payable_id'] !== $payableId
            || (string) $payout['store_id'] !== $storeId
            || strtoupper(trim((string) $payout['currency'])) !== $currency
            || (string) $payout['status'] !== 'success'
            || $amount > (int) $payout['amount_minor']
        ) {
            throw new InvalidArgumentException(
                'The recovery does not match a successful payout.'
            );
        }

        $transactionKey = "payout-recovery:{$recoveryId}:confirmation";

        $existing = $this->ledgerRepository->findTransactionByKey(
            $transactionKey
        );

        if ($existing !== null) {
            $metadata = $existing['metadata'] ?? [];

            if (is_string($metadata)) {
                $metadata = json_decode(
                    $metadata,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }

            if (
                ($existing['status'] ?? null) !== 'posted'
                || ($existing['transaction_type'] ?? null)
                !== 'vendor_payout_recovery'
                || ($existing['payout_id'] ?? null) !== $payoutId
                || (string) ($metadata['recovery_id'] ?? '') !== $recoveryId
                || (int) ($metadata['amount_minor'] ?? 0) !== $amount
            ) {
                throw new RuntimeException(
                    'An incompatible ledger transaction already exists for this recovery.'
                );
            }

            return $existing;
        }

        $clearingAccount = $this->account(
            'PAYOUT_RECOVERY_CLEARING',
            'Vendor Payout Recovery Clearing',
            'asset',
            $currency
        );

        $vendorPayableAccount = $this->account(
            'VENDOR_PAYABLE_' . strtoupper($storeId),
            'Vendor Payable',
            'liability',
            $currency,
            null,
            $storeId
        );

        $transaction = $this->ledgerRepository->createTransaction([
            'transaction_key' => $transactionKey,
            'transaction_type' => 'vendor_payout_recovery',
            'payout_id' => $payoutId,
            'currency' => $currency,
            'description' => 'Vendor payout recovery confirmation',
            'metadata' => [
                'recovery_id' => $recoveryId,
                'payout_id' => $payoutId,
                'vendor_payable_id' => $payableId,
                'store_id' => $storeId,
                'amount_minor' => $amount,
                'return_method' => $recovery['return_method'],
                'confirmation_reference' => $recovery['confirmation_reference'],
            ],
        ]);

        $transactionId = (string) $transaction['id'];

        // Cash recovered from the vendor.
        $this->ledgerRepository->createEntry([
            'transaction_id' => $transactionId,
            'account_id' => $clearingAccount['id'],
            'side' => 'D',
            'amount_minor' => $amount,
            'currency' => $currency,
        ]);

        // Restore the vendor payable liability so the customer refund
        // can subsequently debit the recovered amount.
        $this->ledgerRepository->createEntry([
            'transaction_id' => $transactionId,
            'account_id' => $vendorPayableAccount['id'],
            'side' => 'C',
            'amount_minor' => $amount,
            'currency' => $currency,
        ]);

        $this->ledgerRepository->markTransactionPosted($transactionId);

        return [
            'id' => $transactionId,
            'transaction_key' => $transactionKey,
            'status' => 'posted',
            'amount_minor' => $amount,
        ];
    }
}