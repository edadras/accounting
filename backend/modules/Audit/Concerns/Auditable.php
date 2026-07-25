<?php

declare(strict_types=1);

namespace Modules\Audit\Concerns;

use Illuminate\Support\Str;
use Modules\Audit\Support\AuditRecorder;

/**
 * Records create / update / delete on a model.
 *
 * Only the attributes that actually changed are stored on an update. Writing
 * the whole row every time would bury the one field a reviewer is looking for
 * under thirty that did not move.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            $recorder = app(AuditRecorder::class);
            $recorder->record(
                action: static::auditAction($model, 'created'),
                subject: $model,
                after: $recorder->clean($model->getAttributes()),
            );
        });

        static::updated(function (self $model): void {
            $changed = $model->getChanges();
            $recorder = app(AuditRecorder::class);

            $after = $recorder->clean($changed);

            if ($after === []) {
                return;
            }

            $before = [];
            foreach (array_keys($after) as $key) {
                $before[$key] = $model->getOriginal($key);
            }

            $recorder->record(
                action: static::auditAction($model, 'updated'),
                subject: $model,
                before: $before,
                after: $after,
            );
        });

        static::deleted(function (self $model): void {
            $recorder = app(AuditRecorder::class);
            $recorder->record(
                action: static::auditAction($model, 'deleted'),
                subject: $model,
                before: $recorder->clean($model->getAttributes()),
            );
        });
    }

    private static function auditAction(self $model, string $verb): string
    {
        $parts = explode('\\', $model::class);

        return Str::snake(end($parts)).'.'.$verb;
    }
}
