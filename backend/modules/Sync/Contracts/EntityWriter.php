<?php

declare(strict_types=1);

namespace Modules\Sync\Contracts;

use Illuminate\Database\Eloquent\Model;
use Modules\Sync\Support\SyncEntity;

/**
 * How one registered entity is written when a pushed change is accepted.
 *
 * Most rows are just columns and the default implementation is enough. An
 * entity whose write has consequences beyond its own row — a transaction, whose
 * double-entry postings and account balances have to stay in step — registers
 * its own writer instead of teaching the sync engine about the ledger.
 *
 * `$version` is decided by the sync engine, never by the caller's payload.
 */
interface EntityWriter
{
    /** @param  array<string, mixed>  $attributes */
    public function create(SyncEntity $entity, string $id, array $attributes, int $version): Model;

    /** @param  array<string, mixed>  $attributes */
    public function update(SyncEntity $entity, Model $model, array $attributes, int $version): Model;

    public function delete(SyncEntity $entity, Model $model, int $version): void;
}
