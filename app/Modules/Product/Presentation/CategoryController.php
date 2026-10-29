<?php

declare(strict_types=1);

namespace App\Modules\Product\Presentation;

use App\Modules\Product\Application\Services\CategoryService;
use Phalcon\Mvc\Controller;
use Throwable;

final class CategoryController extends Controller
{
    private CategoryService $categoryService;

    public function initialize(): void
    {
        $this->categoryService = $this->di->getShared('categoryService');
    }

    public function indexAction(): mixed
    {
        try {
            $categories = $this->categoryService->listActive();

            $tree = [];
            $positions = [];

            foreach ($categories as $category) {
                $item = [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'parent_id' => $category->parentId,
                    'subcategories' => [],
                ];

                if ($category->parentId === null) {
                    $tree[] = $item;
                    $positions[$category->id] = array_key_last($tree);
                } elseif (isset($positions[$category->parentId])) {
                    $tree[$positions[$category->parentId]]['subcategories'][] = $item;
                }
            }

            return $this->jsonResponder->success([
                'categories' => $tree,
            ]);
        } catch (Throwable $e) {
            return $this->jsonResponder->error(
                'INTERNAL_SERVER_ERROR',
                'Unable to retrieve active categories.',
                500
            );
        }
    }
}