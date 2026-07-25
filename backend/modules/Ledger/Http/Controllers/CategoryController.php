<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Models\Category;

final class CategoryController
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->when($request->query('type'), fn ($q, $type) => $q->whereIn('type', [$type, 'both']))
            ->orderBy('path')
            ->get();

        if ($request->boolean('tree')) {
            return response()->json(['data' => $this->buildTree($categories)]);
        }

        return response()->json([
            'data' => $categories->map(fn (Category $c) => $this->present($c))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'parent_id' => ['nullable', 'string', 'size:26', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Category::TYPES)],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $category = new Category;

        if (! empty($data['id'])) {
            $category->id = $data['id'];
        }

        $category->fill($data)->save();

        return response()->json(['data' => $this->present($category)], 201);
    }

    /**
     * Assembles the nested tree in one pass over a flat, already-sorted list —
     * no query per level, however deep the user has nested things.
     *
     * @param  Collection<int, Category>  $categories
     * @return list<array<string, mixed>>
     */
    private function buildTree($categories): array
    {
        $nodes = [];
        $roots = [];

        foreach ($categories as $category) {
            $nodes[$category->id] = $this->present($category) + ['children' => []];
        }

        foreach ($categories as $category) {
            $parentId = $category->parent_id;

            if ($parentId !== null && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$nodes[$category->id];
            } else {
                $roots[] = &$nodes[$category->id];
            }
        }

        return $roots;
    }

    /** @return array<string, mixed> */
    private function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'name_key' => $category->name_key,
            'path' => $category->path,
            'depth' => $category->depth,
            'type' => $category->type,
            'icon' => $category->icon,
            'color' => $category->color,
            'is_system' => $category->is_system,
        ];
    }
}
