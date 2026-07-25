<?php

declare(strict_types=1);

namespace Modules\Core\Concerns;

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
            if (empty($model->workspace_id)) {
                $model->workspace_id = app(WorkspaceContext::class)->id();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
