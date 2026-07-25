<?php

declare(strict_types=1);

namespace Modules\Alerts\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Alerts\Support\QuietHours;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * One member's control over how and when this workspace may reach them.
 *
 * Per member and not per workspace: two people sharing a company's books do not
 * share a bedtime.
 */
final class AlertPreference extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    protected $fillable = [
        'workspace_id', 'user_id', 'channels', 'quiet_hours_start',
        'quiet_hours_end', 'timezone',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function forMember(string $workspaceId, int $userId): ?self
    {
        return self::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * A channel the member has not spoken about is on.
     *
     * The database channel cannot be turned off — switching it off would only
     * hide the alert from the app that shows it, not stop it being raised.
     */
    public function enables(string $channel): bool
    {
        if ($channel === ChannelKeys::DATABASE) {
            return true;
        }

        return (bool) (($this->channels ?? [])[$channel] ?? true);
    }

    public function quietHours(?string $fallbackTimezone = null): QuietHours
    {
        return QuietHours::between(
            $this->quiet_hours_start ?? config('alerts.quiet_hours.start'),
            $this->quiet_hours_end ?? config('alerts.quiet_hours.end'),
            $this->timezone ?? $fallbackTimezone,
        );
    }
}
