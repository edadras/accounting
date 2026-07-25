<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Modules\Core\Models\Workspace;

/**
 * One private channel per workspace.
 *
 * Membership is checked here, the same way `ResolveWorkspace` checks it for
 * HTTP: a broadcast channel is another door into the same books, and a door
 * without the check is how one household ends up watching another's spending
 * arrive in real time.
 */
Broadcast::channel('workspace.{workspaceId}', function (User $user, string $workspaceId): bool {
    $workspace = Workspace::query()->find($workspaceId);

    return $workspace !== null && $workspace->memberFor($user) !== null;
});
