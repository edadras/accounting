<?php

declare(strict_types=1);

namespace Modules\Sync\Writers;

use Illuminate\Database\Eloquent\Model;
use Modules\Sync\Contracts\EntityWriter;
use Modules\Sync\Support\SyncEntity;

/**
 * The default writer: it rewrites the columns the registry says the device owns
 * and nothing else.
 */
class AttributeWriter implements EntityWriter
{
    public function create(SyncEntity $entity, string $id, array $attributes, int $version): Model
    {
        $model = $entity->newModel();

        // The client minted this ULID offline; the record keeps the identity it
        // was created with (docs/09-sync-offline.md §3).
        $model->{$model->getKeyName()} = $id;

        return $this->write($entity, $model, $attributes, $version);
    }

    public function update(SyncEntity $entity, Model $model, array $attributes, int $version): Model
    {
        return $this->write($entity, $model, $attributes, $version);
    }

    public function delete(SyncEntity $entity, Model $model, int $version): void
    {
        $model->version = $version;
        $model->save();

        // Soft delete: the row becomes a tombstone the next pull can carry, so a
        // second device learns about the deletion instead of resurrecting it.
        $model->delete();
    }

    /** @param  array<string, mixed>  $attributes */
    protected function write(SyncEntity $entity, Model $model, array $attributes, int $version): Model
    {
        // forceFill rather than fill: the registry is already the whitelist, and
        // the models' own $fillable lists serve the REST controllers, which is a
        // different — and narrower — set of columns than a device owns.
        $model->forceFill($entity->writable($attributes));
        $model->version = $version;
        $model->save();

        return $model;
    }
}
