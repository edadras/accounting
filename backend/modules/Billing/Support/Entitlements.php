<?php

declare(strict_types=1);

namespace Modules\Billing\Support;

use Modules\Billing\Models\Subscription;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;

/**
 * The single place a limit is decided.
 *
 * Every gate in the product — middleware, controller, action, job — asks this
 * class and nothing else. The alternative is a plan rule spelled out in twenty
 * `if` statements, where raising the Free account limit means finding all
 * twenty and the one that was missed silently keeps charging.
 *
 * A missing flag is denied and a missing limit is unlimited: a plan that forgot
 * to mention a new paid feature must not hand it out, and a plan that forgot to
 * mention a counter must not lock a paying workspace out of its own data.
 */
final readonly class Entitlements
{
    /**
     * @param  array<string, bool>  $flags
     * @param  array<string, int|null>  $limits
     */
    public function __construct(
        public string $planCode,
        private array $flags,
        private array $limits,
    ) {}

    public static function forPlan(string $planCode): self
    {
        return new self(
            $planCode,
            PlanRegistry::flags($planCode),
            PlanRegistry::limits($planCode),
        );
    }

    public static function forSubscription(?Subscription $subscription): self
    {
        return self::forPlan($subscription?->effectivePlanCode() ?? PlanRegistry::defaultCode());
    }

    public static function forWorkspace(Workspace|string $workspace): self
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return self::forSubscription(Subscription::forWorkspaceId($id));
    }

    /** The entitlements of the workspace the current request runs in. */
    public static function current(): self
    {
        $id = app(WorkspaceContext::class)->id();

        return $id === null
            ? self::forPlan(PlanRegistry::defaultCode())
            : self::forWorkspace($id);
    }

    public function allows(string $feature): bool
    {
        return $this->flags[$feature] ?? false;
    }

    /** Null means unlimited. */
    public function limit(string $key): ?int
    {
        return $this->limits[$key] ?? null;
    }

    /** Null means unlimited; otherwise never negative, even when already over. */
    public function remaining(string $key, int $used): ?int
    {
        $limit = $this->limit($key);

        return $limit === null ? null : max(0, $limit - $used);
    }

    public function permits(string $key, int $used, int $wanted = 1): bool
    {
        $remaining = $this->remaining($key, $used);

        return $remaining === null || $remaining >= $wanted;
    }

    /** @return array{plan:string,flags:array<string,bool>,limits:array<string,int|null>} */
    public function snapshot(): array
    {
        return [
            'plan' => $this->planCode,
            'flags' => $this->flags,
            'limits' => $this->limits,
        ];
    }
}
