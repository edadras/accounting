<?php

declare(strict_types=1);

namespace Modules\Sync\Support;

use Modules\Sync\Exceptions\SyncException;

/**
 * The exact place a pull stopped.
 *
 * `updated_at` alone is not a position: fifty rows written in the same second
 * share it, and resuming from a timestamp would either skip the rest of that
 * second or hand it out twice. The entity key and the id make the ordering
 * total, so a resumed pull has no gaps and no repeats.
 */
final readonly class Cursor
{
    public function __construct(
        public string $updatedAt,
        public string $entity,
        public string $id,
    ) {}

    public function encode(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode([
            't' => $this->updatedAt,
            'e' => $this->entity,
            'i' => $this->id,
        ])), '+/', '-_'), '=');
    }

    public static function decode(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $json = base64_decode(strtr($value, '-_', '+/'), true);

        if ($json === false) {
            throw SyncException::invalidCursor();
        }

        $parts = json_decode($json, true);

        if (! is_array($parts) || ! isset($parts['t'], $parts['e'], $parts['i'])) {
            throw SyncException::invalidCursor();
        }

        return new self((string) $parts['t'], (string) $parts['e'], (string) $parts['i']);
    }

    /** Negative when $row sorts before this cursor, positive when after. */
    public function compareTo(string $updatedAt, string $entity, string $id): int
    {
        return [$updatedAt, $entity, $id] <=> [$this->updatedAt, $this->entity, $this->id];
    }
}
