<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Persistence;

use App\Modules\Ledger\Domain\Repositories\VendorPayableRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final class PdoVendorPayableRepository implements VendorPayableRepository
{
    public function __construct(
        private readonly AdapterInterface $db
    ) {
    }

    public function createHeldIfNotExists(array $payableData): array
    {
        foreach (
            [
                'vendor_order_id',
                'store_id',
                'currency',
                'gross_minor',
                'commission_minor',
                'payable_minor',
            ] as $field
        ) {
            if (
                !array_key_exists($field, $payableData)
                || $payableData[$field] === null
                || $payableData[$field] === ''
            ) {
                throw new RuntimeException(
                    "Missing required vendor payable field: {$field}"
                );
            }
        }

        $grossMinor = (int) $payableData['gross_minor'];
        $commissionMinor = (int) $payableData['commission_minor'];
        $payableMinor = (int) $payableData['payable_minor'];
        $currency = strtoupper(trim((string) $payableData['currency']));

        if (
            $grossMinor < 0
            || $commissionMinor < 0
            || $payableMinor < 0
        ) {
            throw new RuntimeException(
                'Vendor payable amounts cannot be negative.'
            );
        }

        if ($commissionMinor > $grossMinor) {
            throw new RuntimeException(
                'Vendor commission cannot exceed merchandise gross.'
            );
        }

        if ($payableMinor !== $grossMinor - $commissionMinor) {
            throw new RuntimeException(
                'Vendor payable does not reconcile with gross and commission.'
            );
        }

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException(
                'Invalid vendor payable currency.'
            );
        }

        $result = $this->db->query(
            <<<'SQL'
                INSERT INTO public.vendor_payables (
                    vendor_order_id,
                    store_id,
                    currency,
                    gross_minor,
                    commission_minor,
                    adjustment_minor,
                    refund_minor,
                    payable_minor,
                    status
                )
                VALUES (
                    :vendor_order_id,
                    :store_id,
                    :currency,
                    :gross_minor,
                    :commission_minor,
                    0,
                    0,
                    :payable_minor,
                    'held'
                )
                ON CONFLICT (vendor_order_id) DO NOTHING
                RETURNING *
            SQL,
            [
                'vendor_order_id' => $payableData['vendor_order_id'],
                'store_id' => $payableData['store_id'],
                'currency' => $currency,
                'gross_minor' => $grossMinor,
                'commission_minor' => $commissionMinor,
                'payable_minor' => $payableMinor,
            ]
        );

        $payable = $result->fetch();

        if ($payable) {
            return $payable;
        }

        $existingResult = $this->db->query(
            <<<'SQL'
                SELECT *
                FROM public.vendor_payables
                WHERE vendor_order_id = :vendor_order_id
                LIMIT 1
                FOR UPDATE
            SQL,
            [
                'vendor_order_id' => $payableData['vendor_order_id'],
            ]
        );

        $existingPayable = $existingResult->fetch();

        if (!$existingPayable) {
            throw new RuntimeException(
                'Unable to create or retrieve vendor payable.'
            );
        }

        if (
            (string) $existingPayable['store_id']
            !== (string) $payableData['store_id']
            || strtoupper((string) $existingPayable['currency']) !== $currency
            || (int) $existingPayable['gross_minor'] !== $grossMinor
            || (int) $existingPayable['commission_minor'] !== $commissionMinor
            || (int) $existingPayable['payable_minor'] !== $payableMinor
        ) {
            throw new RuntimeException(
                'Existing vendor payable does not match the payment allocation. '
                . 'Manual reconciliation is required.'
            );
        }

        return $existingPayable;
    }
}