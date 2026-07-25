<?php

declare(strict_types=1);

namespace Modules\Alerts\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Support\ScannerRegistry;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;

/**
 * Sweeps every workspace's active rules and raises whatever they justify.
 *
 * Queued and scheduled hourly. Running it more often is harmless and running it
 * twice at once is harmless, because nothing here decides whether an alert is
 * new — RaiseAlert and the unique index do.
 */
final class ScanForAlerts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ?string $workspaceId = null) {}

    /** @return int the number of alerts raised */
    public function handle(
        WorkspaceContext $context,
        ScannerRegistry $scanners,
        RaiseAlert $raise,
    ): int {
        $raised = 0;
        $now = CarbonImmutable::now();

        foreach ($this->workspaces() as $workspace) {
            $raised += $context->runFor(
                $workspace,
                fn (): int => $this->scanWorkspace($workspace, $scanners, $raise, $now),
            );
        }

        return $raised;
    }

    private function scanWorkspace(
        Workspace $workspace,
        ScannerRegistry $scanners,
        RaiseAlert $raise,
        CarbonImmutable $now,
    ): int {
        $raised = 0;
        $rules = AlertRule::query()->active()->get();
        $recipients = [];

        foreach ($rules as $rule) {
            $scanner = $scanners->get($rule->type);
            $targets = $recipients[$rule->id] ??= $this->recipientsFor($workspace, $rule);

            foreach ($scanner->scan($rule, $now) as $candidate) {
                foreach ($targets as $recipient) {
                    $raised += $raise->handle($workspace, $rule, $candidate, $recipient) === null ? 0 : 1;
                }
            }
        }

        return $raised;
    }

    /**
     * Who this rule speaks to.
     *
     * The owner unless the rule names people: telling every member of a company
     * workspace about every cheque is how a notification system gets muted.
     *
     * @return list<User>
     */
    private function recipientsFor(Workspace $workspace, AlertRule $rule): array
    {
        $ids = $rule->setting('recipients');

        if (! is_array($ids) || $ids === []) {
            $ids = [$workspace->owner_id];
        }

        // Membership is re-checked here: a rule naming someone who has since
        // left must not keep sending them the workspace's business.
        return User::query()
            ->whereIn('id', $ids)
            ->whereIn('id', $workspace->members()->select('user_id'))
            ->get()
            ->all();
    }

    /** @return iterable<Workspace> */
    private function workspaces(): iterable
    {
        $query = Workspace::query()->whereNull('archived_at');

        if ($this->workspaceId !== null) {
            $query->where('id', $this->workspaceId);
        }

        return $query->cursor();
    }
}
