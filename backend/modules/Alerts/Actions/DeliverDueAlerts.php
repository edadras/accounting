<?php

declare(strict_types=1);

namespace Modules\Alerts\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Alerts\Models\Alert;
use Modules\Core\Support\WorkspaceContext;

/**
 * Sends the alerts whose moment has come.
 *
 * This is the other half of quiet hours: RaiseAlert parks an alert at the edge
 * of the window, and this — scheduled every few minutes — is what actually
 * lets it out afterwards.
 */
final class DeliverDueAlerts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @return int the number of alerts delivered */
    public function handle(WorkspaceContext $context, DeliverAlert $deliver): int
    {
        $delivered = 0;

        $alerts = Alert::query()
            ->withoutWorkspaceScope()
            ->due(CarbonImmutable::now())
            ->with(['user', 'workspace'])
            ->orderBy('scheduled_at')
            ->get();

        foreach ($alerts as $alert) {
            if ($alert->workspace === null || $alert->user === null) {
                continue;
            }

            $context->runFor($alert->workspace, fn () => $deliver->handle($alert, $alert->user));
            $delivered++;
        }

        return $delivered;
    }
}
