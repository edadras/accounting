<?php

declare(strict_types=1);

namespace Modules\Alerts\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * What a workspace wants to be told about, on which channels, how early.
 */
final class AlertRule extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const TYPE_CHECK_DUE = 'check_due';

    public const TYPE_INSTALLMENT_DUE = 'installment_due';

    public const TYPE_BUDGET_THRESHOLD = 'budget_threshold';

    public const TYPE_LOW_BALANCE = 'low_balance';

    public const TYPES = [
        self::TYPE_CHECK_DUE,
        self::TYPE_INSTALLMENT_DUE,
        self::TYPE_BUDGET_THRESHOLD,
        self::TYPE_LOW_BALANCE,
    ];

    protected $fillable = [
        'workspace_id', 'type', 'config', 'channels', 'lead_days', 'is_active',
    ];

    protected $attributes = [
        'channels' => '["database"]',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'channels' => 'array',
            'lead_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return list<string> */
    public function channelKeys(): array
    {
        $channels = is_array($this->channels) ? $this->channels : [];

        // The database channel is not optional: it is the alert row itself, and
        // without it an alert would exist that the user can never see in-app.
        return array_values(array_unique([...$channels, ChannelKeys::DATABASE]));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    public function leadDays(): int
    {
        return max(0, $this->lead_days ?? (int) config('alerts.default_lead_days', 3));
    }
}
