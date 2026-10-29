<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Domain\Entities\Inventory;
use App\Modules\Product\Domain\Repositories\InventoryRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoInventoryRepository implements InventoryRepository
{
    public function __construct(private AdapterInterface $db)
    {
    }

    public function create(Inventory $inventory): void
    {
        $sql = <<<'SQL'
            INSERT INTO public.inventory (
                variant_id,
                available_qty,
                reserved_qty
            ) VALUES (
                :variant_id,
                :available_qty,
                :reserved_qty
            )
        SQL;

        $result = $this->db->query($sql, [
            'variant_id' => $inventory->variantId,
            'available_qty' => $inventory->availableQty,
            'reserved_qty' => $inventory->reservedQty,
        ]);

        if ($result === false) {
            throw new RuntimeException('Failed to initialize product inventory.');
        }
    }
}