<?php

declare(strict_types=1);

namespace Modules\Billing\Actions;

use Carbon\CarbonImmutable;
use Modules\Billing\Exceptions\BillingException;
use Modules\Billing\Models\Subscription;
use Modules\Core\Models\Workspace;

/**
 * Ends a subscription without taking anything away today.
 *
 * The workspace keeps the plan it paid for until `renews_at`, then falls back
 * to the default plan on its own. A trial cancels immediately, because there is
 * no paid period to honour.
 */
final class CancelSubscription
{
    public function handle(Workspace $workspace): Subscription
    {
        $subscription = Subscription::forWorkspaceId($workspace->id)
            ?? throw BillingException::noSubscription();

        $now = CarbonImmutable::now();

        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => $now,
            'renews_at' => $subscription->status === Subscription::STATUS_TRIALING
                ? null
                : $subscription->renews_at,
        ])->save();

        return $subscription;
    }
}
