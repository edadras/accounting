<?php

declare(strict_types=1);

namespace Modules\Alerts\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Modules\Alerts\Models\Alert;
use Modules\Alerts\Models\AlertPreference;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Support\AlertCandidate;
use Modules\Core\Models\Workspace;

/**
 * Turns a candidate into an alert for one person, or into nothing at all.
 *
 * Everything that makes alerting bearable happens here: the same cheque is
 * recorded once and never again, channels the member switched off are dropped
 * before anything is sent, and an alert landing in their quiet hours is moved
 * to the far edge of the window rather than thrown away.
 */
final readonly class RaiseAlert
{
    public function __construct(private DeliverAlert $deliver) {}

    public function handle(
        Workspace $workspace,
        AlertRule $rule,
        AlertCandidate $candidate,
        User $recipient,
    ): ?Alert {
        $preference = AlertPreference::forMember($workspace->id, (int) $recipient->id);

        $channels = $this->channelsFor($rule, $preference);

        $quietHours = ($preference ?? new AlertPreference)->quietHours($workspace->timezone);
        $scheduledAt = $quietHours->nextAllowed($candidate->scheduledAt);

        $alert = $this->persist($workspace, $candidate, $recipient, $channels, $scheduledAt);

        if ($alert === null) {
            return null;
        }

        return $scheduledAt->isFuture()
            ? $alert
            : $this->deliver->handle($alert, $recipient);
    }

    /** @return array<string, string> */
    private function channelsFor(AlertRule $rule, ?AlertPreference $preference): array
    {
        $channels = [];

        foreach ($rule->channelKeys() as $key) {
            if ($preference === null || $preference->enables($key)) {
                $channels[$key] = Alert::DELIVERY_PENDING;
            }
        }

        return $channels;
    }

    /**
     * Writes the alert, or returns null because it already exists.
     *
     * The lookup answers the ordinary case — the hourly rescan that finds the
     * same cheque still due. The catch answers the case the lookup cannot: two
     * workers scanning at once both find nothing and both insert, and the loser
     * of that race must be discarded rather than crash the scan.
     *
     * @param  array<string, string>  $channels
     */
    private function persist(
        Workspace $workspace,
        AlertCandidate $candidate,
        User $recipient,
        array $channels,
        CarbonImmutable $scheduledAt,
    ): ?Alert {
        $alreadyRaised = Alert::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $recipient->id)
            ->where('dedupe_key', $candidate->dedupeKey)
            ->exists();

        if ($alreadyRaised) {
            return null;
        }

        $alert = new Alert;
        $alert->forceFill([
            'workspace_id' => $workspace->id,
            'user_id' => $recipient->id,
            'type' => $candidate->type,
            'payload' => $candidate->payload,
            'scheduled_at' => $scheduledAt,
            'channels' => $channels,
            'status' => Alert::STATUS_PENDING,
            'dedupe_key' => $candidate->dedupeKey,
        ]);

        try {
            $alert->save();
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return null;
            }

            throw $e;
        }

        return $alert;
    }

    private function isDuplicate(QueryException $e): bool
    {
        // 23000/23505 are the SQL-standard integrity-violation classes, which is
        // what SQLite, MySQL and Postgres all report a unique clash as.
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
