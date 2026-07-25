<?php

declare(strict_types=1);

namespace Modules\Core\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Workspace;
use Modules\Core\Scopes\WorkspaceScope;
use Modules\Core\Support\WorkspaceContext;

/**
 * Applies the workspace global scope and stamps workspace_id on create, so a
 * developer cannot forget either.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(WorkspaceScope::NAME, new WorkspaceScope);

        static::creating(function (self $model): void {
            // Through the attribute bag rather than the property: the trait is
            // mixed into models it knows nothing about, and workspace_id is
            // their column, not the trait's own.
            if (empty($model->getAttribute('workspace_id'))) {
                // require() rather than id(): with no active workspace the old
                // code stamped null and the row died on a NOT NULL violation
                // several layers away from the mistake.
                $model->setAttribute('workspace_id', app(WorkspaceContext::class)->require()->id);
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The one sanctioned way past the tenant filter, for system-level work
     * (indexers, schedulers, billing counters) that legitimately spans
     * workspaces.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutWorkspaceScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(WorkspaceScope::NAME);
    }
}
