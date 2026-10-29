<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Domain\Entities\InventoryMovement;
use App\Modules\Product\Domain\Repositories\InventoryMovementRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoInventoryMovementRepository implements InventoryMovementRepository
{
    public function __construct(private AdapterInterface $db)
    {
    }

    public function create(InventoryMovement $movement): void
    {
        $sql = <<<'SQL'
            INSERT INTO public.inventory_movements (
                id,
                variant_id,
                movement_type,
                quantity_delta,
                available_before,
                available_after,
                reserved_before,
                reserved_after,
                reason,
                reference_id,
                actor_user_id,
                created_at
            ) VALUES (
                :id,
                :variant_id,
                :movement_type,
                :quantity_delta,
                :available_before,
                :available_after,
                :reserved_before,
                :reserved_after,
                :reason,
                :reference_id,
                :actor_user_id,
                :created_at
            )
        SQL;

        $result = $this->db->query($sql, [
            'id' => $movement->id,
            'variant_id' => $movement->variantId,
            'movement_type' => $movement->movementType,
            'quantity_delta' => $movement->quantityDelta,
            'available_before' => $movement->availableBefore,
            'available_after' => $movement->availableAfter,
            'reserved_before' => $movement->reservedBefore,
            'reserved_after' => $movement->reservedAfter,
            'reason' => $movement->reason,
            'reference_id' => $movement->referenceId,
            'actor_user_id' => $movement->actorUserId,
            'created_at' => $movement->createdAt->format('Y-m-d H:i:sP'),
        ]);

        if ($result === false) {
            throw new RuntimeException('Failed to record inventory movement.');
        }
    }
}