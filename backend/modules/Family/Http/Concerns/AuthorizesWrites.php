<?php

declare(strict_types=1);

namespace Modules\Family\Http\Concerns;

use Illuminate\Http\Request;
use Modules\Core\Models\WorkspaceMember;

/**
 * Everybody in the household may read its books; only writing roles may change
 * them — which is what keeps a child from raising their own allowance.
 *
 * The member was resolved and verified once by ResolveWorkspace — this only
 * checks the role it found.
 */
trait AuthorizesWrites
{
    protected function assertCanWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }
}
