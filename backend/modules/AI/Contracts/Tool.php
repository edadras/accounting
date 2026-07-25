<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

/**
 * A read-only question the model is allowed to ask of the books.
 *
 * The model never writes SQL and never names a workspace: it picks a tool and
 * supplies arguments, and the tool queries through Eloquent, where the
 * workspace global scope is already applied (docs/07-security.md §5.1–5.2).
 */
interface Tool
{
    /** Stable snake_case identifier the model calls. */
    public function name(): string;

    public function description(): string;

    /**
     * JSON schema of the accepted arguments.
     *
     * A schema that mentions a workspace would be a bug: the registry rejects
     * any such argument before the tool ever sees it.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * @param  array<string, mixed>  $arguments  already sanitised by the registry
     * @return array<string, mixed>
     */
    public function run(array $arguments): array;
}
