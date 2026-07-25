<?php

declare(strict_types=1);

namespace Modules\Sync\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Modules\Sync\Contracts\EntityWriter;
use Modules\Sync\Writers\AttributeWriter;

/**
 * One entry of the syncable registry: a wire key, the model behind it, and the
 * fields a device is allowed to write.
 */
final readonly class SyncEntity
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $fields
     * @param  list<string>  $financial
     * @param  class-string<EntityWriter>  $writer
     */
    public function __construct(
        public string $key,
        public string $model,
        public array $fields,
        public array $financial,
        public string $writer = AttributeWriter::class,
    ) {}

    public function newModel(): Model
    {
        return new $this->model;
    }

    /**
     * Every lookup goes through the model's own query builder, so the workspace
     * global scope applies and one device can never reach another tenant's row.
     *
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        $query = $this->model::query();

        // What `withTrashed()` does, spelled out: the macro only exists on
        // builders whose model uses SoftDeletes, and the registry holds a bare
        // class-string, so the scope is lifted by name instead.
        return $this->isSoftDeletable()
            ? $query->withoutGlobalScope(SoftDeletingScope::class)
            : $query;
    }

    public function isSoftDeletable(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->model), true);
    }

    public function isFinancial(string $field): bool
    {
        return in_array($field, $this->financial, true);
    }

    /**
     * The payload reduced to the fields this entity actually owns.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function writable(array $payload): array
    {
        return array_intersect_key($payload, array_flip($this->fields));
    }

    public function writerInstance(): EntityWriter
    {
        return app($this->writer);
    }
}
