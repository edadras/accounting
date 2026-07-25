<?php

declare(strict_types=1);

namespace Modules\Billing\Support;

use Modules\Budget\Models\Budget;
use Modules\Core\Models\Workspace;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Models\Account;

/**
 * How much of each countable limit a workspace is already using.
 *
 * Read-only against the modules that own those tables — billing counts them,
 * it never touches them.
 *
 * `workspaces` counts what the owner has across the whole account rather than
 * inside the current workspace, because "1 workspace" is a limit on the person,
 * not on the books they are looking at.
 */
final class UsageCounter
{
    private const KEYS = ['workspaces', 'accounts', 'members', 'budgets'];

    public static function supports(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    public function count(string $key, Workspace $workspace): int
    {
        return match ($key) {
            'workspaces' => Workspace::query()->where('owner_id', $workspace->owner_id)->count(),
            'accounts' => Account::query()->withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count(),
            'members' => WorkspaceMember::query()->where('workspace_id', $workspace->id)->count(),
            'budgets' => Budget::query()->withoutWorkspaceScope()->where('workspace_id', $workspace->id)->count(),
            default => 0,
        };
    }

    /** @return array<string, int> */
    public function all(Workspace $workspace): array
    {
        $counts = [];

        foreach (self::KEYS as $key) {
            $counts[$key] = $this->count($key, $workspace);
        }

        return $counts;
    }
}
