<?php

declare(strict_types=1);

namespace Modules\Recurring\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Recurring\Exceptions\RecurringException;

/**
 * A standing instruction: the rent on the 5th, the salary on the 1st, the
 * subscription every 30 days.
 *
 * The schedule is deliberately a small subset of RRULE — frequency, interval,
 * day of month, day of week, an end date — because that is what a household
 * ledger needs, and a full RRULE parser is a library's job rather than a
 * column's.
 */
final class RecurringRule extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;
    use SoftDeletes;

    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    public const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY, self::YEARLY];

    /** Template keys without which nothing can be posted. */
    private const REQUIRED_TEMPLATE_KEYS = ['type', 'account_id', 'amount', 'currency'];

    protected $fillable = [
        'workspace_id', 'name', 'template', 'frequency', 'interval',
        'day_of_month', 'day_of_week', 'starts_at', 'ends_at',
        'next_run_at', 'last_run_at', 'auto_post', 'is_paused',
    ];

    protected function casts(): array
    {
        return [
            'template' => 'array',
            'interval' => 'integer',
            'day_of_month' => 'integer',
            'day_of_week' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'auto_post' => 'boolean',
            'is_paused' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $rule): void {
            // A rule that has never run is due at its own start; leaving
            // next_run_at to the poster would mean the first occurrence is only
            // scheduled once something else has already looked at the rule.
            $rule->next_run_at ??= $rule->starts_at;
        });
    }

    /**
     * Rules with an occurrence owed at $at.
     *
     * `auto_post` rules only: a rule with auto_post false is a reminder, and
     * posting it would put money in the books the user never confirmed.
     */
    public function scopeDue(Builder $query, \DateTimeInterface $at): Builder
    {
        return $query
            ->where('is_paused', false)
            ->where('auto_post', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $at);
    }

    /** The occurrence after $from, or null once the rule has run past its end. */
    public function occurrenceAfter(\DateTimeInterface $from): ?CarbonImmutable
    {
        $moment = CarbonImmutable::instance(
            $from instanceof \DateTimeImmutable ? $from : \DateTimeImmutable::createFromInterface($from)
        );

        $interval = max(1, (int) $this->interval);

        $next = match ($this->frequency) {
            self::DAILY => $moment->addDays($interval),
            self::WEEKLY => $moment->addWeeks($interval),
            self::MONTHLY => $this->onRequestedDay($moment->addMonthsNoOverflow($interval)),
            self::YEARLY => $this->onRequestedDay($moment->addYearsNoOverflow($interval)),
            default => throw RecurringException::unknownFrequency((string) $this->frequency),
        };

        return $this->hasEnded($next) ? null : $next;
    }

    public function hasEnded(?\DateTimeInterface $moment): bool
    {
        return $moment !== null
            && $this->ends_at !== null
            && $moment > $this->ends_at;
    }

    /**
     * The transaction payload for the occurrence at $occurredAt.
     *
     * The idempotency key is what makes a second run of the poster on the same
     * day a no-op even if `next_run_at` was never advanced: RecordTransaction
     * returns the existing transaction rather than writing a second one.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(\DateTimeInterface $occurredAt): array
    {
        $template = is_array($this->template) ? $this->template : [];

        $missing = array_values(array_filter(
            self::REQUIRED_TEMPLATE_KEYS,
            fn (string $key): bool => ! isset($template[$key]),
        ));

        if ($missing !== []) {
            throw RecurringException::incompleteTemplate((string) $this->id, $missing);
        }

        return array_merge($template, [
            'occurred_at' => $occurredAt,
            'source' => 'recurring',

            // The Ledger table has no recurring_rule_id column, and growing one
            // for this module would be the coupling the module layout exists to
            // avoid, so the link travels in source_meta.
            'source_meta' => array_merge(
                is_array($template['source_meta'] ?? null) ? $template['source_meta'] : [],
                ['recurring_rule_id' => $this->id],
            ),
            'idempotency_key' => $this->occurrenceKey($occurredAt),
        ]);
    }

    public function occurrenceKey(\DateTimeInterface $occurredAt): string
    {
        return 'recurring:'.$this->id.':'.$occurredAt->format('Y-m-d');
    }

    /**
     * Pins a monthly or yearly occurrence to the requested day, clamped to the
     * length of the month — "the 31st" in February is the 28th, not a skipped
     * month.
     */
    private function onRequestedDay(CarbonImmutable $moment): CarbonImmutable
    {
        if ($this->day_of_month === null) {
            return $moment;
        }

        return $moment->day(min($this->day_of_month, $moment->daysInMonth));
    }
}
