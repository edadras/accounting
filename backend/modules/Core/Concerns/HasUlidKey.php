<?php

declare(strict_types=1);

namespace Modules\Core\Concerns;

use Illuminate\Support\Str;

/**
 * ULID primary keys, generated client-side when offline.
 *
 * The client mints the id before the record ever reaches the server, so an
 * offline transaction already has its final identity and sync never has to map
 * a temporary id onto a real one. See docs/09-sync-offline.md.
 */
trait HasUlidKey
{
    public static function bootHasUlidKey(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::ulid();
            }
        });
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
