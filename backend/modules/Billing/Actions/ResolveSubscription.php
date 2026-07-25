<?php

declare(strict_types=1);

namespace Modules\Billing\Actions;

use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\PlanRegistry;
use Modules\Core\Models\Workspace;

/**
 * The subscription row for a workspace, created on the default plan if it has
 * none yet.
 *
 * A workspace without a row is on the default plan already — see
 * Entitlements::forSubscription — so this exists only for the paths that need
 * something to write to.
 */
final class ResolveSubscription
{
    public function handle(Workspace $workspace): Subscription
    {
        $existing = Subscription::forWorkspaceId($workspace->id);

        if ($existing !== null) {
            return $existing;
        }

        $subscription = new Subscription;
        $subscription->forceFill([
            'workspace_id' => $workspace->id,
            'plan_code' => PlanRegistry::defaultCode(),
            'status' => Subscription::STATUS_ACTIVE,
        ])->save();

        return $subscription->refresh();
    }
}
