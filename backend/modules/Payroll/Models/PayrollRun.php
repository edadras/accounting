<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Support\PayPeriod;

/**
 * One payroll cycle: a period, the payslips inside it, and where it is in its
 * life.
 *
 * draft → approved → paid, one way only.
 *   draft     nothing has reached the books; the payslips may be rebuilt.
 *   approved  the cost has been posted to the ledger. This is the step that
 *             moves money into the accounts, which is why it is also the step
 *             that has to be idempotent.
 *   paid      history. Nothing may change it, and the model refuses rather
 *             than trusting every caller to remember.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $reference
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable|null $pay_date
 * @property string $currency
 * @property int $gross_total
 * @property int $deduction_total
 * @property int $contribution_total
 * @property int $net_total
 * @property string $status
 * @property string $account_id
 * @property string|null $category_id
 * @property string|null $net_transaction_id
 * @property string|null $liability_transaction_id
 * @property CarbonImmutable|null $approved_at
 * @property string|null $approved_by
 * @property CarbonImmutable|null $paid_at
 * @property string|null $notes
 * @property int $version
 */
final class PayrollRun extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_PAID];

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'reference', 'period_start', 'period_end', 'pay_date',
        'currency', 'gross_total', 'deduction_total', 'contribution_total',
        'net_total', 'status', 'account_id', 'category_id', 'net_transaction_id',
        'liability_transaction_id', 'approved_at', 'approved_by', 'paid_at', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'pay_date' => 'immutable_date',
            'gross_total' => 'integer',
            'deduction_total' => 'integer',
            'contribution_total' => 'integer',
            'net_total' => 'integer',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * A paid run is refused at the model, not only in the actions.
     *
     * Immutability enforced in one action is immutability until somebody adds
     * a second write path; enforced here it holds for every path there will
     * ever be, including a careless `->update()` in a console command.
     */
    public static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->getOriginal('status') === self::STATUS_PAID) {
                throw PayrollException::runIsPaid((string) $run->getOriginal('reference'));
            }
        });

        static::deleting(function (self $run): void {
            if ($run->getOriginal('status') === self::STATUS_PAID) {
                throw PayrollException::runIsPaid((string) $run->getOriginal('reference'));
            }
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function netTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'net_transaction_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function liabilityTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'liability_transaction_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** True once the ledger has seen this run. A draft posts nothing. */
    public function isPosted(): bool
    {
        return $this->net_transaction_id !== null || $this->liability_transaction_id !== null;
    }

    public function period(): PayPeriod
    {
        return new PayPeriod($this->period_start, $this->period_end);
    }

    public function gross(): Money
    {
        return Money::of($this->gross_total, $this->currency);
    }

    public function deductions(): Money
    {
        return Money::of($this->deduction_total, $this->currency);
    }

    public function contributions(): Money
    {
        return Money::of($this->contribution_total, $this->currency);
    }

    public function net(): Money
    {
        return Money::of($this->net_total, $this->currency);
    }

    /** Gross plus the employer's own charges — what the run really costs. */
    public function employerCost(): Money
    {
        return $this->gross()->plus($this->contributions());
    }

    /** What is withheld from the employees plus what the employer owes on top. */
    public function liabilities(): Money
    {
        return $this->deductions()->plus($this->contributions());
    }

    public function assertNotPaid(): void
    {
        if ($this->isPaid()) {
            throw PayrollException::runIsPaid($this->reference);
        }
    }

    /**
     * Re-derives the totals from the payslips actually stored.
     *
     * The totals are a cache over the payslips, exactly as an account balance
     * is a cache over its entries — so they are recomputed from them rather
     * than incremented, and cannot drift.
     */
    public function recalculateTotals(): self
    {
        $this->assertNotPaid();

        $payslips = $this->payslips()->get();

        $this->gross_total = (int) $payslips->sum('gross');
        $this->deduction_total = (int) $payslips->sum('deduction_total');
        $this->contribution_total = (int) $payslips->sum('contribution_total');
        $this->net_total = (int) $payslips->sum('net');

        if ($this->net_total !== $this->gross_total - $this->deduction_total) {
            throw PayrollException::inconsistentRunTotals(
                $this->gross_total - $this->deduction_total,
                $this->net_total,
            );
        }

        $this->save();

        return $this;
    }

    /**
     * @param  Builder<PayrollRun>  $query
     * @return Builder<PayrollRun>
     */
    public function scopeOfStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<PayrollRun>  $query
     * @return Builder<PayrollRun>
     */
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date);
    }
}
