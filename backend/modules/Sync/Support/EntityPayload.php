<?php

declare(strict_types=1);

namespace Modules\Sync\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a record into the shape the wire carries, and back into something two
 * values can be compared by.
 */
final class EntityPayload
{
    /** docs/05-api-conventions.md §1: always ISO-8601 in UTC. */
    public const TIMESTAMP = 'Y-m-d\TH:i:s\Z';

    /**
     * The server's view of a record: the fields the device owns, plus the three
     * facts sync itself needs.
     *
     * @return array<string, mixed>
     */
    public static function of(SyncEntity $entity, Model $model): array
    {
        $payload = [];

        foreach ($entity->fields as $field) {
            $payload[$field] = self::wire($model->getAttribute($field));
        }

        return $payload + [
            'id' => (string) $model->getKey(),
            'version' => self::versionOf($model),
            'updated_at' => self::wire(self::timestampOf($model, 'updated_at')),
            'deleted_at' => self::wire($model->getAttribute('deleted_at')),
        ];
    }

    /**
     * The sync version a record carries.
     *
     * Read through `getAttribute` because the registry holds a bare model class:
     * a row written before the column existed, or a model that never had one,
     * counts as version 0 rather than blowing up mid-batch.
     */
    public static function versionOf(Model $model): int
    {
        $version = $model->getAttribute('version');

        return is_numeric($version) ? (int) $version : 0;
    }

    /** A timestamp column read back as a date, or null when the row carries none. */
    public static function timestampOf(Model $model, string $column): ?DateTimeInterface
    {
        $value = $model->getAttribute($column);

        return $value instanceof DateTimeInterface ? $value : null;
    }

    /** JSON-safe representation of a single attribute. */
    public static function wire(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface
            ? $value->format(self::TIMESTAMP)
            : $value;
    }

    /**
     * A form in which two values from different sources can be compared.
     *
     * The same fact arrives spelled differently depending on who wrote it — a
     * date as a Carbon or as `2026-07-25T09:00:00Z`, an amount as `5000` or
     * `"5000"`, a rate as `1` or `1.000000000000`. Comparing the spellings
     * would report a conflict where there is no disagreement.
     */
    public static function comparable(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_array($value) => self::canonical($value),
            is_numeric($value) => rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.'),
            default => (string) $value,
        };
    }

    /** A key-order-independent JSON rendering, so two equal payloads hash alike. */
    public static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            $sorted = $value;

            if (! array_is_list($sorted)) {
                ksort($sorted);
            }

            foreach ($sorted as $key => $item) {
                $sorted[$key] = is_array($item) ? json_decode(self::canonical($item), true) : $item;
            }

            $value = $sorted;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
