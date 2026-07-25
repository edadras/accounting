<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * A model's request to run one server-side tool.
 *
 * `arguments` is whatever the model asked for and is therefore untrusted: the
 * registry strips workspace identifiers out of it before anything runs.
 */
final readonly class ToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public string $id = '',
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $arguments = $payload['arguments'] ?? [];

        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : [];
        }

        return new self(
            (string) ($payload['name'] ?? ''),
            is_array($arguments) ? $arguments : [],
            (string) ($payload['id'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }
}
