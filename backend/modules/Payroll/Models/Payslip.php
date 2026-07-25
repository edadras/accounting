<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Core\Models\Workspace;
use Modules\Payroll\Exceptions\PayrollException;

/**
 * What one employee is owed for one period, and why.
 *
 * `net` is stored rather than derived so a payslip issued years ago still says
 * what it said then — and `assertReconciles()` is the guard that the stored
 * number never parts company with the lines beneath it.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $payroll_run_id
 * @property string $employee_id
 * @property string $currency
 * @property int $gross
 * @property int $deduction_total
 * @property int $contribution_total
 * @property int $net
 * @property int $period_days
 * @property int $worked_days
 * @property string $country
 * @property string $tax_rules_name
 * @property int $version
 */
final class Payslip extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'payroll_run_id', 'employee_id', 'currency', 'gross',
        'deduction_total', 'contribution_total', 'net', 'period_days',
        'worked_days', 'country', 'tax_rules_name',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gross' => 'integer',
            'deduction_total' => 'integer',
            'contribution_total' => 'integer',
            'net' => 'integer',
            'period_days' => 'integer',
            'worked_days' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<PayslipLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function grossMoney(): Money
    {
        return Money::of($this->gross, $this->currency);
    }

    public function deductionsMoney(): Money
    {
        return Money::of($this->deduction_total, $this->currency);
    }

    public function contributionsMoney(): Money
    {
        return Money::of($this->contribution_total, $this->currency);
    }

    public function netMoney(): Money
    {
        return Money::of($this->net, $this->currency);
    }

    public function employerCost(): Money
    {
        return $this->grossMoney()->plus($this->contributionsMoney());
    }

    public function isProrated(): bool
    {
        return $this->worked_days < $this->period_days;
    }

    /**
     * net = gross − deductions, exactly, in minor units.
     *
     * Checked against the stored columns and against the stored lines, because
     * the two can only disagree if something went wrong, and the wrong thing
     * to do about that is pay somebody the wrong amount quietly.
     */
    public function assertReconciles(): void
    {
        if ($this->net !== $this->gross - $this->deduction_total) {
            throw PayrollException::inconsistentPayslip($this->gross, $this->deduction_total, $this->net);
        }

        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $earnings = (int) $lines->where('kind', PayslipLine::KIND_EARNING)->sum('amount');
        $deductions = (int) $lines->where('kind', PayslipLine::KIND_DEDUCTION)->sum('amount');

        if ($earnings !== $this->gross || $deductions !== $this->deduction_total) {
            throw PayrollException::inconsistentPayslip($earnings, $deductions, $this->net);
        }
    }
}
