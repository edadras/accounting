<?php

declare(strict_types=1);

namespace Modules\Sync\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Which of the fields a device owns does its payload actually disagree with the
 * server about.
 *
 * Field-level rather than record-level: two devices editing different corners of
 * the same transaction are not in conflict, and treating them as one would send
 * the user to the conflict screen for nothing (docs/09-sync-offline.md §6).
 */
final class PayloadDiff
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function changedFields(SyncEntity $entity, Model $model, array $payload): array
    {
        $incoming = $entity->writable($payload);

        if ($incoming === []) {
            return [];
        }

        // Run the incoming values through a model of the same class so the same
        // casts apply to both sides of the comparison.
        $candidate = $model->newInstance()->forceFill($incoming);

        $changed = [];

        foreach (array_keys($incoming) as $field) {
            $before = EntityPayload::comparable($model->getAttribute($field));
            $after = EntityPayload::comparable($candidate->getAttribute($field));

            if ($before !== $after) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function financial(SyncEntity $entity, array $fields): array
    {
        return array_values(array_filter($fields, $entity->isFinancial(...)));
    }
}
