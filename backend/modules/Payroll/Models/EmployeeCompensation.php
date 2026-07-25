<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Core\Models\Workspace;

/**
 * What an employee is paid, and from when.
 *
 * A raise is a new row, not an edit: a payslip issued last March must still be
 * able to explain itself at last March's rate.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $employee_id
 * @property int $amount
 * @property string $currency
 * @property string $period
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property int $version
 */
final class EmployeeCompensation extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    /**
     * Named explicitly: the inflector reads "compensation" as uncountable and
     * would look for `employee_compensation`.
     *
     * @var string
     */
    protected $table = 'employee_compensations';

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'employee_id', 'amount', 'currency', 'period',
        'effective_from', 'effective_to',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
