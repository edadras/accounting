<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Actions\CancelSubscription;
use Modules\Billing\Actions\ChangePlan;
use Modules\Billing\Actions\ResolveSubscription;
use Modules\Billing\Actions\StartTrial;
use Modules\Billing\Http\Resources\SubscriptionResource;
use Modules\Billing\Support\Entitlements;
use Modules\Billing\Support\UsageCounter;
use Modules\Core\Support\WorkspaceContext;

final class SubscriptionController
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function show(ResolveSubscription $resolve, UsageCounter $usage): JsonResponse
    {
        $workspace = $this->context->require();
        $subscription = $resolve->handle($workspace);

        return response()->json([
            'data' => new SubscriptionResource($subscription),

            // Shipped alongside the subscription so the client can grey out
            // what the plan does not include without reimplementing the rules.
            'entitlements' => Entitlements::forSubscription($subscription)->snapshot(),
            'usage' => $usage->all($workspace),
        ]);
    }

    public function store(Request $request, ChangePlan $change): JsonResponse
    {
        $data = $request->validate(['plan_code' => ['required', 'string', 'max:32']]);

        $subscription = $change->handle($this->context->require(), $data['plan_code']);

        return response()->json(['data' => new SubscriptionResource($subscription)]);
    }

    public function trial(Request $request, StartTrial $start): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'max:32'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $subscription = $start->handle(
            $this->context->require(),
            $data['plan_code'],
            isset($data['days']) ? (int) $data['days'] : null,
        );

        return (new SubscriptionResource($subscription))->response()->setStatusCode(201);
    }

    public function cancel(CancelSubscription $cancel): JsonResponse
    {
        $subscription = $cancel->handle($this->context->require());

        return response()->json(['data' => new SubscriptionResource($subscription)]);
    }
}
