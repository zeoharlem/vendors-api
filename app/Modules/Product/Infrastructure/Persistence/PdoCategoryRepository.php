<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Domain\Entities\Category;
use App\Modules\Product\Domain\Repositories\CategoryRepository;
use Phalcon\Contracts\Db\Adapter\Adapter as AdapterInterface;
use RuntimeException;

final readonly class PdoCategoryRepository implements CategoryRepository
{
    public function __construct(private AdapterInterface $db)
    {
    }

    public function create(Category $category): Category
    {
        $sql = <<<'SQL'
            INSERT INTO public.categories (
                id, parent_id, name, slug, description,
                is_active, created_at, updated_at
            ) VALUES (
                :id, :parent_id, :name, :slug, :description,
                :is_active, :created_at, :updated_at
            )
            RETURNING *
        SQL;

        return $this->fetchCategory($sql, $this->params($category));
    }

    public function update(Category $category): Category
    {
        $params = $this->params($category);
        unset($params['created_at']);

        $sql = <<<'SQL'
            UPDATE public.categories
            SET parent_id = :parent_id,
                name = :name,
                slug = :slug,
                description = :description,
                is_active = :is_active,
                updated_at = :updated_at
            WHERE id = :id
            RETURNING *
        SQL;

        return $this->fetchCategory($sql, $params);
    }

    public function findById(string $id): ?Category
    {
        $result = $this->db->query(
            'SELECT * FROM public.categories WHERE id = :id LIMIT 1',
            ['id' => $id],
        );

        $row = $result->fetch();

        return is_array($row) && isset($row['id'])
            ? $this->hydrate($row)
            : null;
    }

    public function findAll(): array
    {
        $result = $this->db->query(
            'SELECT * FROM public.categories ORDER BY name ASC'
        );

        $rows = $result->fetchAll();
        $categories = [];

        foreach ($rows as $row) {
            $categories[] = $this->hydrate($row);
        }

        return $categories;
    }

    public function findActiveTree(): array
    {
        $sql = <<<'SQL'
            SELECT c.*
            FROM public.categories c
            LEFT JOIN public.categories parent ON parent.id = c.parent_id
            WHERE c.is_active = TRUE
              AND (
                c.parent_id IS NULL
                OR (
                  parent.is_active = TRUE
                  AND parent.parent_id IS NULL
                )
              )
            ORDER BY c.parent_id NULLS FIRST, c.name ASC
        SQL;

        $result = $this->db->query($sql);
        $categories = [];

        foreach ($result->fetchAll() as $row) {
            $categories[] = $this->hydrate($row);
        }

        return $categories;
    }

    public function slugExists(string $slug, ?string $excludeId = null): bool
    {
        $sql = 'SELECT id FROM public.categories WHERE slug = :slug';
        $params = ['slug' => $slug];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $sql .= ' LIMIT 1';

        $result = $this->db->query($sql, $params);
        $row = $result->fetch();

        return is_array($row) && isset($row['id']);
    }

    public function hasChildren(string $categoryId): bool
    {
        $result = $this->db->query(
            'SELECT id FROM public.categories WHERE parent_id = :id LIMIT 1',
            ['id' => $categoryId],
        );

        $row = $result->fetch();

        return is_array($row) && isset($row['id']);
    }

    public function hasProducts(string $categoryId): bool
    {
        $result = $this->db->query(
            'SELECT id FROM public.products WHERE category_id = :id LIMIT 1',
            ['id' => $categoryId],
        );

        $row = $result->fetch();

        return is_array($row) && isset($row['id']);
    }

    private function fetchCategory(string $sql, array $params): Category
    {
        $result = $this->db->query($sql, $params);
        $row = $result->fetch();

        if (!is_array($row) || !isset($row['id'])) {
            throw new RuntimeException('Category persistence operation failed.');
        }

        return $this->hydrate($row);
    }

    private function params(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parentId,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'is_active' => $category->isActive,
            'created_at' => $category->createdAt->format('Y-m-d H:i:sP'),
            'updated_at' => $category->updatedAt->format('Y-m-d H:i:sP'),
        ];
    }

    private function hydrate(array $row): Category
    {
        return new Category(
            id: (string) $row['id'],
            parentId: $row['parent_id'] === null
            ? null
            : (string) $row['parent_id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            description: $row['description'] === null
            ? null
            : (string) $row['description'],
            isActive: filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN),
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}