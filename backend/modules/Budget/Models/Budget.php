<?php

declare(strict_types=1);

namespace Modules\Budget\Models;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Category;

/**
 * A spending ceiling for a period, either over everything or over one slice of
 * the books (a category subtree, a project, a trip, a building, a member).
 */
final class Budget extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;
    use SoftDeletes;

    public const SCOPE_OVERALL = 'overall';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPES = ['overall', 'category', 'project', 'trip', 'building', 'member'];

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    public const PERIOD_CUSTOM = 'custom';

    public const PERIODS = [self::PERIOD_MONTHLY, self::PERIOD_YEARLY, self::PERIOD_CUSTOM];

    /** @var list<int> */
    public const DEFAULT_ALERT_THRESHOLDS = [80, 100];

    protected $fillable = [
        'workspace_id', 'name', 'scope', 'scope_id', 'period',
        'starts_at', 'ends_at', 'amount', 'currency', 'rollover',
        'alert_thresholds',
    ];

    protected $attributes = [
        'alert_thresholds' => '[80,100]',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'amount' => 'integer',
            'rollover' => 'boolean',
            'alert_thresholds' => 'array',
            'version' => 'integer',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(BudgetUsage::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    /**
     * The thresholds, ascending and free of duplicates.
     *
     * Falls back to the product default for rows written before a client sent
     * any, so the alerting code never has to handle null.
     *
     * @return list<int>
     */
    public function alertThresholds(): array
    {
        $thresholds = $this->alert_thresholds;

        if (! is_array($thresholds) || $thresholds === []) {
            return self::DEFAULT_ALERT_THRESHOLDS;
        }

        $thresholds = array_values(array_unique(array_map('intval', $thresholds)));
        sort($thresholds);

        return $thresholds;
    }

    /**
     * The period containing $at, as [start, end].
     *
     * Monthly and yearly budgets align to the calendar rather than to an
     * anniversary of `starts_at`: the user compares this month against last
     * month, and a budget created on the 17th must not report a period that
     * straddles two months.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function periodWindow(?\DateTimeInterface $at = null): array
    {
        $at = $this->moment($at);

        return match ($this->period) {
            self::PERIOD_MONTHLY => [$at->startOfMonth(), $at->endOfMonth()],
            self::PERIOD_YEARLY => [$at->startOfYear(), $at->endOfYear()],
            default => [
                CarbonImmutable::instance($this->starts_at)->startOfDay(),
                CarbonImmutable::instance($this->ends_at ?? $this->starts_at)->endOfDay(),
            ],
        };
    }

    /**
     * The period immediately before the one containing $at, or null when there
     * is none — either because the budget is a one-off custom window, or
     * because the previous period predates the budget itself.
     *
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    public function previousPeriodWindow(?\DateTimeInterface $at = null): ?array
    {
        $at = $this->moment($at);

        $previous = match ($this->period) {
            self::PERIOD_MONTHLY => $at->subMonthNoOverflow(),
            self::PERIOD_YEARLY => $at->subYear(),
            default => null,
        };

        if ($previous === null) {
            return null;
        }

        $window = $this->periodWindow($previous);

        return $window[1]->lessThan(CarbonImmutable::instance($this->starts_at))
            ? null
            : $window;
    }

    public function periodKey(?\DateTimeInterface $at = null): string
    {
        $at = $this->moment($at);

        return match ($this->period) {
            self::PERIOD_MONTHLY => $at->format('Y-m'),
            self::PERIOD_YEARLY => $at->format('Y'),
            default => CarbonImmutable::instance($this->starts_at)->format('Y-m-d')
                .'..'.CarbonImmutable::instance($this->ends_at ?? $this->starts_at)->format('Y-m-d'),
        };
    }

    /** The category this budget covers, or null when it is not category-scoped. */
    public function targetCategory(): ?Category
    {
        if ($this->scope !== self::SCOPE_CATEGORY || $this->scope_id === null) {
            return null;
        }

        return Category::query()->find($this->scope_id);
    }

    private function moment(?\DateTimeInterface $at): CarbonImmutable
    {
        return $at === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance(
                $at instanceof \DateTimeImmutable ? $at : \DateTimeImmutable::createFromInterface($at)
            );
    }
}
