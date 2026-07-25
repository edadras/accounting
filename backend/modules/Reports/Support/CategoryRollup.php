<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use Illuminate\Support\Collection;
use Modules\Ledger\Models\Category;

/**
 * Folds a spend on a deep category into the ancestor the user asked to see.
 *
 * Categories nest to unlimited depth, so a raw "top categories" list is mostly
 * leaves — "restaurant", "bread", "taxi" — and never answers the question the
 * user is actually asking, which is "how much on food". Rolling a subtree up to
 * a chosen depth answers it: at depth 2, '/home/food/restaurant' reports under
 * '/home/food'.
 *
 * Trashed categories are resolved too. Soft-deleting a category leaves its old
 * transactions pointing at it, and dropping them would quietly shrink history.
 */
final class CategoryRollup
{
    public const UNCATEGORIZED = 'uncategorized';

    /** @var Collection<string, Category> keyed by id */
    private Collection $byId;

    /** @var Collection<string, Category> keyed by path */
    private Collection $byPath;

    public function __construct()
    {
        $categories = Category::query()->withTrashed()->get();

        $this->byId = $categories->keyBy('id');
        $this->byPath = $categories->keyBy('path');
    }

    /**
     * The bucket a category rolls into at $depth, where depth counts path
     * segments: depth 1 is '/home', depth 2 is '/home/food'.
     *
     * @return array{key:string,category_id:?string,path:?string,name:string,depth:int}
     */
    public function bucketFor(?string $categoryId, int $depth): array
    {
        $category = $categoryId === null ? null : $this->byId->get($categoryId);

        if ($category === null) {
            return [
                'key' => self::UNCATEGORIZED,
                'category_id' => null,
                'path' => null,
                'name' => self::UNCATEGORIZED,
                'depth' => 0,
            ];
        }

        $segments = array_values(array_filter(
            explode('/', (string) $category->path),
            static fn (string $segment): bool => $segment !== '',
        ));
        $kept = array_slice($segments, 0, max(1, $depth));
        $path = '/'.implode('/', $kept);

        $ancestor = $this->byPath->get($path);

        return [
            'key' => $path,
            'category_id' => $ancestor?->id,
            'path' => $path,
            'name' => $ancestor->name ?? (string) end($kept),
            'depth' => count($kept),
        ];
    }
}
