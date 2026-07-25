<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Audit\Concerns\Auditable;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A spending or income category, nested to unlimited depth:
 * خانه ← خوراک ← رستوران.
 *
 * Depth is unlimited by design — the roadmap promises the user can add a level
 * whenever they want — so the tree is stored as a materialized path and never
 * walked recursively at query time.
 */
final class Category extends Model
{
    use Auditable;
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;
    use SoftDeletes;

    public const TYPES = ['income', 'expense', 'both'];

    protected $fillable = [
        'workspace_id', 'parent_id', 'name', 'name_key',
        'type', 'icon', 'color', 'is_system', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'depth' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // path and depth are derived, never supplied by the client.
        self::saving(function (self $category): void {
            if ($category->isDirty(['parent_id', 'name']) || ! $category->exists) {
                $category->refreshPath();
            }
        });

        // Moving a subtree has to rewrite the paths beneath it.
        self::updated(function (self $category): void {
            if ($category->wasChanged('path')) {
                $category->rewriteDescendantPaths();
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function refreshPath(): void
    {
        $slug = Str::slug($this->name, '-', null) ?: Str::lower(Str::random(6));
        $parent = $this->parent_id ? self::withoutGlobalScopes()->find($this->parent_id) : null;

        $this->path = $parent ? rtrim($parent->path, '/').'/'.$slug : '/'.$slug;
        $this->depth = substr_count($this->path, '/') - 1;
    }

    public function rewriteDescendantPaths(): void
    {
        $original = $this->getOriginal('path');

        if (! $original || $original === $this->path) {
            return;
        }

        foreach (self::query()->where('parent_id', $this->id)->get() as $child) {
            $child->refreshPath();
            $child->save();
        }
    }

    /** All categories at or beneath this one — one indexed prefix scan. */
    public function scopeInSubtreeOf(Builder $query, self $category): Builder
    {
        return $query->where(function (Builder $q) use ($category): void {
            $q->where('path', $category->path)
                ->orWhere('path', 'like', rtrim($category->path, '/').'/%');
        });
    }

    /** @return list<string> */
    public function descendantIds(): array
    {
        return self::query()
            ->inSubtreeOf($this)
            ->pluck('id')
            ->all();
    }

    /** Human-readable trail: "خانه ← خوراک ← رستوران" */
    public function breadcrumb(string $separator = ' ← '): string
    {
        $names = [$this->name];
        $node = $this;

        while ($node->parent_id !== null) {
            $node = $node->parent()->first();

            if ($node === null) {
                break;
            }

            array_unshift($names, $node->name);
        }

        return implode($separator, $names);
    }
}
