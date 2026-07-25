<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Billing\Exceptions\BillingException;
use Modules\Billing\Support\Entitlements;
use Modules\Billing\Support\UsageCounter;
use Modules\Core\Support\WorkspaceContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level gate: `->middleware('entitlement:ai')`.
 *
 * One key covers both shapes of limit. A key the UsageCounter knows how to
 * count is a countable one and is checked against what the workspace already
 * holds; anything else is a plain on/off feature. Either way the answer comes
 * from Entitlements, so a route never spells out a number.
 */
final class RequiresEntitlement
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly UsageCounter $usage,
    ) {}

    public function handle(Request $request, Closure $next, string $key, int $wanted = 1): Response
    {
        $entitlements = Entitlements::current();

        if (UsageCounter::supports($key)) {
            $used = $this->usage->count($key, $this->context->require());

            if (! $entitlements->permits($key, $used, $wanted)) {
                throw BillingException::limitReached(
                    $entitlements->planCode,
                    $key,
                    (int) $entitlements->limit($key),
                    $used,
                );
            }

            return $next($request);
        }

        if (! $entitlements->allows($key)) {
            throw BillingException::featureNotInPlan($entitlements->planCode, $key);
        }

        return $next($request);
    }
}
