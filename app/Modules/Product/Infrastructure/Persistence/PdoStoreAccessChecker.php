<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Contracts\StoreAccessCheckerInterface;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use Phalcon\Db\Enum;

final readonly class PdoStoreAccessChecker implements StoreAccessCheckerInterface
{
    public function __construct(
        private AdapterInterface $db,
    ) {
    }

    public function canManageStore(string $userId, string $storeId): bool
    {
        $sql = <<<'SQL'
            SELECT 1
            FROM public.stores s
            INNER JOIN public.vendor_profiles vp
                ON vp.id = s.vendor_profile_id
            WHERE s.id = :store_id
              AND vp.user_id = :user_id
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'store_id' => $storeId,
            'user_id' => $userId,
        ]);
        return $result !== false && $result !== [];
        //return $result->fetch(Enum::FETCH_ASSOC) !== false;
    }
}
