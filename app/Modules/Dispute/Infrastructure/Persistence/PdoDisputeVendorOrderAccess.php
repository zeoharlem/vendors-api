<?php

declare(strict_types=1);

namespace App\Modules\Dispute\Infrastructure\Persistence;

use App\Modules\Dispute\Domain\Contracts\DisputeVendorOrderAccessInterface;
use App\Modules\Dispute\Domain\Exceptions\DisputeAccessDeniedException;
use App\Modules\Dispute\Domain\ValueObjects\DisputableVendorOrder;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;

final readonly class PdoDisputeVendorOrderAccess implements DisputeVendorOrderAccessInterface
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    public function getForCustomer(
        string $vendorOrderId,
        string $customerId
    ): DisputableVendorOrder {
        $result = $this->db->query(
            <<<'SQL'
                SELECT
                    vo.id::text AS vendor_order_id,
                    vo.order_id::text AS order_id,
                    vp.id::text AS vendor_payable_id
                FROM public.vendor_orders vo
                INNER JOIN public.orders o
                    ON o.id = vo.order_id
                LEFT JOIN public.vendor_payables vp
                    ON vp.vendor_order_id = vo.id
                WHERE vo.id = :vendor_order_id
                AND o.customer_id = :customer_id
                LIMIT 1
                FOR UPDATE OF vo, o
            SQL,
            [
                'vendor_order_id' => $vendorOrderId,
                'customer_id' => $customerId,
            ]
        );

        $row = $result->fetch();

        if ($row === false || $row === null) {
            throw new DisputeAccessDeniedException(
                'You are not permitted to open a dispute for this vendor order.'
            );
        }

        return new DisputableVendorOrder(
            vendorOrderId: (string) $row['vendor_order_id'],
            orderId: (string) $row['order_id'],
            vendorPayableId: $row['vendor_payable_id'] !== null
            ? (string) $row['vendor_payable_id']
            : null,
        );
    }
}
