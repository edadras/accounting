<?php

declare(strict_types=1);

namespace Modules\Billing\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the billing module, carrying a machine-readable code and the
 * HTTP status the API should answer with.
 *
 * Same contract as LedgerException (docs/05-api-conventions.md).
 */
final class BillingException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function unknownPlan(string $code): self
    {
        return new self('unknown_plan', "Plan [{$code}] does not exist.", 404, ['plan' => $code]);
    }

    /** A boolean feature the current plan does not include. */
    public static function featureNotInPlan(string $plan, string $feature): self
    {
        return new self(
            'plan_limit_reached',
            "The {$plan} plan does not include [{$feature}].",
            403,
            ['plan' => $plan, 'feature' => $feature, 'limit' => 0],
        );
    }

    /** A countable limit the workspace has already reached. */
    public static function limitReached(string $plan, string $key, int $limit, int $used): self
    {
        return new self(
            'plan_limit_reached',
            "The {$plan} plan allows {$limit} {$key}; {$used} are already in use.",
            403,
            ['plan' => $plan, 'feature' => $key, 'limit' => $limit, 'used' => $used],
        );
    }

    /**
     * A downgrade that would leave the workspace over the target plan's limits.
     *
     * Refused rather than performed, because the only way to honour it would be
     * to delete the user's data to make it fit.
     *
     * @param  list<array{feature:string,limit:int,used:int}>  $violations
     */
    public static function downgradeBlocked(string $from, string $to, array $violations): self
    {
        $summary = implode(', ', array_map(
            fn (array $v) => "{$v['used']} {$v['feature']} against a limit of {$v['limit']}",
            $violations,
        ));

        return new self(
            'downgrade_blocked',
            "Cannot move from {$from} to {$to}: the workspace still has {$summary}. "
                .'Remove what is over the limit first; nothing is deleted automatically.',
            422,
            ['from' => $from, 'to' => $to, 'violations' => $violations],
        );
    }

    public static function alreadyOnPlan(string $code): self
    {
        return new self('already_on_plan', "The workspace is already on the {$code} plan.", 422, ['plan' => $code]);
    }

    public static function trialAlreadyUsed(string $workspaceId): self
    {
        return new self(
            'trial_already_used',
            'This workspace has already used its trial.',
            422,
            ['workspace_id' => $workspaceId],
        );
    }

    public static function paymentFailed(string $gateway, string $reason): self
    {
        return new self(
            'payment_failed',
            "The {$gateway} gateway refused the charge: {$reason}",
            402,
            ['gateway' => $gateway, 'reason' => $reason],
        );
    }

    public static function noSubscription(): self
    {
        return new self('subscription_not_found', 'This workspace has no subscription.', 404);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
                'request_id' => request()->header('X-Request-Id'),
            ],
        ], $this->status);
    }
}
