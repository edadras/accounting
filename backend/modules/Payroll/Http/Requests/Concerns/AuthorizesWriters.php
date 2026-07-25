<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests\Concerns;

use Modules\Core\Models\WorkspaceMember;

/**
 * Payroll is a write on the books, so it needs a role that may write on them.
 *
 * The membership itself was already verified by ResolveWorkspace; this is the
 * second of the three layers docs/07-security.md asks for, and a viewer fails
 * it with 403.
 */
trait AuthorizesWriters
{
    public function authorize(): bool
    {
        $member = $this->attributes->get('workspace_member');

        return $member instanceof WorkspaceMember && $member->canWrite();
    }
}
