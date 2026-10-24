<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence;

use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Catalog\Domain\Repository\ProductRepository;
use Phalcon\Db\Adapter\Pdo\Postgresql;

/**
 * Infrastructure adapter. Queries are parameterized.
 * Add tenant/store publication policy here; do not accept vendor ownership from client input.
 */
final readonly class PdoProductRepository implements ProductRepository
{
    public function __construct(private Postgresql $db)
    {
    }

    public function findPublished(int $limit, int $offset): array
    {
        $sql = 'SELECT id, vendor_id, name, slug, price_minor, currency, is_active
                FROM products
                WHERE is_active = TRUE
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset';

        $rows = $this->db->fetchAll($sql, \Phalcon\Db\Enum::FETCH_ASSOC, [
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return array_map(fn (array $row): Product => $this->hydrate($row), $rows);
    }

    public function findById(string $id): ?Product
    {
        $row = $this->db->fetchOne(
            'SELECT id, vendor_id, name, slug, price_minor, currency, is_active
             FROM products WHERE id = :id AND is_active = TRUE',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['id' => $id]
        );

        return is_array($row) && $row !== [] ? $this->hydrate($row) : null;
    }

    private function hydrate(array $row): Product
    {
        return new Product(
            (string) $row['id'],
            (string) $row['vendor_id'],
            (string) $row['name'],
            (string) $row['slug'],
            (int) $row['price_minor'],
            (string) $row['currency'],
            (bool) $row['is_active'],
        );
    }
}
