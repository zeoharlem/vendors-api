<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Domain\Repositories\ProductCategoryRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;

final readonly class PdoProductCategoryRepository implements ProductCategoryRepository
{
    public function __construct(private AdapterInterface $db)
    {
    }

    public function isSelectable(string $categoryId): bool
    {
        $sql = <<<'SQL'
            SELECT c.id
            FROM public.categories c
            LEFT JOIN public.categories parent
                ON parent.id = c.parent_id
            WHERE c.id = :category_id
              AND c.is_active = TRUE
              AND (
                  c.parent_id IS NULL
                  OR (
                      parent.id IS NOT NULL
                      AND parent.is_active = TRUE
                      AND parent.parent_id IS NULL
                  )
              )
            LIMIT 1
        SQL;

        $result = $this->db->query($sql, [
            'category_id' => $categoryId,
        ]);

        $row = $result->fetch();

        return is_array($row) && isset($row['id']);
    }
}