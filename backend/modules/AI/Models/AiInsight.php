<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * One finding about the books, with the arithmetic that produced it.
 *
 * `data` is not decoration: an insight the user cannot audit is a claim, and
 * these are claims about their money.
 */
final class AiInsight extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const TYPE_COMPOSITION = 'spending_composition';

    public const TYPE_PERIOD_CHANGE = 'period_change';

    public const TYPE_ANOMALY = 'spending_anomaly';

    public const TYPE_FORECAST = 'cashflow_forecast';

    public const TYPES = [
        self::TYPE_COMPOSITION,
        self::TYPE_PERIOD_CHANGE,
        self::TYPE_ANOMALY,
        self::TYPE_FORECAST,
    ];

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    protected $fillable = [
        'workspace_id', 'type', 'severity', 'title', 'body', 'data',
        'period_start', 'period_end', 'score', 'fingerprint', 'computed_at', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'score' => 'float',
            'computed_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
