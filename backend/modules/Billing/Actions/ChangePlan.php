<?php

declare(strict_types=1);

namespace Modules\Billing\Actions;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Exceptions\BillingException;
use Modules\Billing\Models\Plan;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\SubscriptionInvoice;
use Modules\Billing\Support\Entitlements;
use Modules\Billing\Support\PlanRegistry;
use Modules\Billing\Support\UsageCounter;
use Modules\Core\Models\Workspace;

/**
 * Moves a workspace between plans, in either direction.
 *
 * Upgrades charge and invoice. Downgrades are checked against what the
 * workspace actually holds first and refused if they would not fit — see
 * violationsFor.
 */
final readonly class ChangePlan
{
    public function __construct(
        private ResolveSubscription $resolve,
        private UsageCounter $usage,
        private PaymentGateway $gateway,
    ) {}

    public function handle(Workspace $workspace, string $planCode): Subscription
    {
        if (! PlanRegistry::has($planCode)) {
            throw BillingException::unknownPlan($planCode);
        }

        $subscription = $this->resolve->handle($workspace);
        $current = $subscription->effectivePlanCode();

        if ($current === $planCode && $subscription->status === Subscription::STATUS_ACTIVE) {
            throw BillingException::alreadyOnPlan($planCode);
        }

        if (PlanRegistry::rank($planCode) < PlanRegistry::rank($current)) {
            $violations = $this->violationsFor($workspace, $planCode);

            if ($violations !== []) {
                throw BillingException::downgradeBlocked($current, $planCode, $violations);
            }
        }

        $plan = PlanRegistry::get($planCode);
        $price = Money::of((int) $plan['price'], (string) $plan['currency']);
        $now = CarbonImmutable::now();
        $periodEnd = $plan['interval'] === Plan::INTERVAL_YEARLY ? $now->addYear() : $now->addMonth();

        $invoice = $price->isPositive()
            ? $this->settle($subscription, $planCode, $price, $now, $periodEnd)
            : null;

        $subscription->forceFill([
            'plan_code' => $planCode,
            'status' => Subscription::STATUS_ACTIVE,
            'renews_at' => $invoice === null ? null : $periodEnd,
            'cancelled_at' => null,
            'gateway' => $invoice?->gateway,
            'gateway_reference' => $invoice?->gateway_reference,
        ])->save();

        return $subscription;
    }

    /**
     * The countable limits the target plan would already be over.
     *
     * Reported in full rather than one at a time, so a user clearing them out
     * does not discover the next one only after fixing the first. Nothing is
     * deleted to make room: that is the user's data and their decision.
     *
     * @return list<array{feature:string,limit:int,used:int}>
     */
    public function violationsFor(Workspace $workspace, string $planCode): array
    {
        $target = Entitlements::forPlan($planCode);
        $violations = [];

        foreach ($this->usage->all($workspace) as $key => $used) {
            $limit = $target->limit($key);

            if ($limit !== null && $used > $limit) {
                $violations[] = ['feature' => $key, 'limit' => $limit, 'used' => $used];
            }
        }

        return $violations;
    }

    /**
     * Invoices and charges, leaving a row behind either way.
     *
     * The failed invoice is committed before the refusal is thrown — a decline
     * the user can see beats one that only exists in a log.
     */
    private function settle(
        Subscription $subscription,
        string $planCode,
        Money $price,
        CarbonImmutable $now,
        CarbonImmutable $periodEnd,
    ): SubscriptionInvoice {
        $invoice = new SubscriptionInvoice;
        $invoice->forceFill([
            'workspace_id' => $subscription->workspace_id,
            'subscription_id' => $subscription->id,
            'number' => 'INV-'.$now->format('Ym').'-'.strtoupper(substr((string) Str::ulid(), -10)),
            'plan_code' => $planCode,
            'amount' => $price->minorUnits,
            'currency' => $price->currency->code,
            'status' => SubscriptionInvoice::STATUS_PENDING,
            'period_start' => $now,
            'period_end' => $periodEnd,
            'issued_at' => $now,
            'gateway' => $this->gateway->name(),
        ])->save();

        $result = $this->gateway->charge($subscription, $price, ['invoice' => $invoice->number]);

        if (! $result->successful) {
            $invoice->forceFill(['status' => SubscriptionInvoice::STATUS_FAILED])->save();

            throw BillingException::paymentFailed($this->gateway->name(), $result->message);
        }

        $invoice->forceFill([
            'status' => SubscriptionInvoice::STATUS_PAID,
            'paid_at' => $now,
            'gateway_reference' => $result->reference,
        ])->save();

        return $invoice;
    }
}
