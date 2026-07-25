<?php

declare(strict_types=1);

namespace Modules\Core\Support;

use Modules\Core\Models\Workspace;

/**
 * Holds the workspace the current request operates in.
 *
 * Registered as a singleton and populated by ResolveWorkspace middleware after
 * membership has been verified. Nothing else may set it — in particular it is
 * never read from a request body or an AI prompt.
 */
final class WorkspaceContext
{
    private ?Workspace $workspace = null;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function forget(): void
    {
        $this->workspace = null;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?string
    {
        return $this->workspace?->id;
    }

    public function baseCurrency(): string
    {
        return $this->workspace?->base_currency ?? 'USD';
    }

    public function require(): Workspace
    {
        return $this->workspace ?? throw new \RuntimeException(
            'No active workspace. Send the X-Workspace-Id header.'
        );
    }

    /** Runs $callback with $workspace active, restoring the previous one after. */
    public function runFor(Workspace $workspace, callable $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback();
        } finally {
            $this->workspace = $previous;
        }
    }
}
