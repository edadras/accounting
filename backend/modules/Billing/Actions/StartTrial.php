<?php

declare(strict_types=1);

namespace Modules\Billing\Actions;

use Carbon\CarbonImmutable;
use Modules\Billing\Exceptions\BillingException;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\PlanRegistry;
use Modules\Core\Models\Workspace;

final readonly class StartTrial
{
    public function __construct(private ResolveSubscription $resolve) {}

    public function handle(Workspace $workspace, string $planCode, ?int $days = null): Subscription
    {
        if (! PlanRegistry::has($planCode)) {
            throw BillingException::unknownPlan($planCode);
        }

        $subscription = $this->resolve->handle($workspace);

        if ($subscription->hasUsedTrial()) {
            throw BillingException::trialAlreadyUsed($workspace->id);
        }

        $subscription->forceFill([
            'plan_code' => $planCode,
            'status' => Subscription::STATUS_TRIALING,
            'trial_ends_at' => CarbonImmutable::now()->addDays($days ?? PlanRegistry::trialDays()),

            // No renewal date: nothing has been paid for, so when the trial ends
            // the subscription resolves to the default plan and stays there
            // until someone actually buys something.
            'renews_at' => null,
            'cancelled_at' => null,
        ])->save();

        return $subscription;
    }
}
