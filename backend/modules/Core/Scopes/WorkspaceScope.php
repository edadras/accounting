<?php

declare(strict_types=1);

namespace Modules\Core\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Support\WorkspaceContext;

/**
 * Constrains every domain query to the active workspace.
 *
 * This is the single most important line of defence in the product: a leak
 * across workspaces is a severe security bug, not a display glitch. Because it
 * is a global scope, forgetting a `where` in one controller cannot cause one.
 *
 * Bypassing it requires the explicit, greppable `withoutWorkspaceScope()`,
 * declared as a real query scope on BelongsToWorkspace so the escape hatch is
 * visible to static analysis instead of hiding behind a builder macro.
 */
final class WorkspaceScope implements Scope
{
    public const NAME = 'workspace';

    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        if ($workspaceId === null) {
            // No active workspace: return nothing rather than everything.
            // Failing closed turns a wiring mistake into an empty list instead
            // of another tenant's books.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
