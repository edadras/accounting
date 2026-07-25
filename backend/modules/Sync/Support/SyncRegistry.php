<?php

declare(strict_types=1);

namespace Modules\Sync\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Sync\Contracts\EntityWriter;
use Modules\Sync\Exceptions\SyncException;
use Modules\Sync\Writers\AttributeWriter;

/**
 * The closed set of records a device may synchronise.
 *
 * This is the whitelist the wire protocol is checked against. `entity` arrives
 * from the client as a bare string, and the only thing standing between that
 * string and `new $class` is this lookup — so nothing here ever derives a class
 * name from the request, and an unregistered key is a refusal, not a fallback.
 */
final class SyncRegistry
{
    /** @var array<string, SyncEntity>|null */
    private ?array $entities = null;

    /** @param  array<string, array<string, mixed>>  $config */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('sync.entities', []));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, SyncEntity> */
    public function all(): array
    {
        return $this->entities ??= $this->build();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function resolve(string $key): SyncEntity
    {
        return $this->all()[$key] ?? throw SyncException::unknownEntity($key, $this->keys());
    }

    /** The wire key for a model instance or class, or null when it is not syncable. */
    public function keyFor(Model|string $model): ?string
    {
        $class = $model instanceof Model ? $model::class : $model;

        foreach ($this->all() as $key => $entity) {
            if ($entity->model === $class) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<string, SyncEntity> */
    private function build(): array
    {
        $entities = [];

        foreach ($this->config as $key => $definition) {
            $class = (string) ($definition['model'] ?? '');

            if (! is_subclass_of($class, Model::class)) {
                throw SyncException::misconfiguredEntity((string) $key, $class);
            }

            $writer = (string) ($definition['writer'] ?? AttributeWriter::class);

            // The writer is handed to the container and asked to write rows, so
            // it is held to the same standard as the model: a config typo is a
            // refusal at boot rather than an unknown class at push time.
            if (! is_a($writer, EntityWriter::class, true)) {
                throw SyncException::misconfiguredWriter((string) $key, $writer);
            }

            $entities[(string) $key] = new SyncEntity(
                key: (string) $key,
                model: $class,
                fields: array_values(array_map(strval(...), (array) ($definition['fields'] ?? []))),
                financial: array_values(array_map(strval(...), (array) ($definition['financial'] ?? []))),
                writer: $writer,
            );
        }

        return $entities;
    }
}
