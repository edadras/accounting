<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Core\Models\Workspace;

/**
 * Somebody the workspace pays.
 *
 * `country` sits here rather than only on the workspace because a company can
 * employ someone abroad, and it is the country that decides which tax schedule
 * prices their payslip.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $employee_number
 * @property string $name
 * @property string|null $job_title
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $national_id
 * @property string $country
 * @property string $status
 * @property CarbonImmutable $started_on
 * @property CarbonImmutable|null $ended_on
 * @property string|null $notes
 * @property int $version
 */
final class Employee extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_ENDED = 'ended';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_ON_LEAVE, self::STATUS_ENDED];

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'employee_number', 'name', 'job_title', 'email', 'phone',
        'national_id', 'country', 'status', 'started_on', 'ended_on', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_on' => 'immutable_date',
            'ended_on' => 'immutable_date',

            // docs/07-security.md: a national identifier never sits in the
            // clear. Encrypted here means it cannot be searched — deliberately.
            'national_id' => 'encrypted',

            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<EmployeeCompensation, $this> */
    public function compensations(): HasMany
    {
        return $this->hasMany(EmployeeCompensation::class)->orderByDesc('effective_from');
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * The compensation in force on $date.
     *
     * A raise does not rewrite last quarter's payslips, so the rate is looked
     * up by date rather than read off the employee.
     */
    public function compensationOn(DateTimeInterface|string $date): ?EmployeeCompensation
    {
        $on = CarbonImmutable::parse($date)->toDateString();

        return $this->compensations()
            ->where('effective_from', '<=', $on)
            ->where(function (Builder $query) use ($on): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $on);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    public function hasEnded(): bool
    {
        return $this->status === self::STATUS_ENDED;
    }

    /** Was this person employed on any day between $start and $end? */
    public function wasEmployedBetween(DateTimeInterface|string $start, DateTimeInterface|string $end): bool
    {
        if ($this->hasEnded()) {
            return false;
        }

        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();

        if ($this->started_on->greaterThan($to)) {
            return false;
        }

        return $this->ended_on === null || ! $this->ended_on->lessThan($from);
    }

    /**
     * The people a run covering $start–$end may pay.
     *
     * Somebody whose employment has ended is excluded here, at the query, so
     * that leaving them out of a new run is not a thing a caller has to
     * remember to do.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeEmployableBetween(Builder $query, DateTimeInterface|string $start, DateTimeInterface|string $end): Builder
    {
        $from = CarbonImmutable::parse($start)->toDateString();
        $to = CarbonImmutable::parse($end)->toDateString();

        return $query
            ->where('status', '!=', self::STATUS_ENDED)
            ->whereDate('started_on', '<=', $to)
            ->where(function (Builder $inner) use ($from): void {
                $inner->whereNull('ended_on')->orWhereDate('ended_on', '>=', $from);
            });
    }
}
