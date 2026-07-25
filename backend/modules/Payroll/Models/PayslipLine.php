<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Core\Models\Workspace;
use Modules\Payroll\Support\PayslipLineDraft;

/**
 * One itemised line of a payslip: a wage, a tax withheld, an employer charge.
 *
 * Storing the rate alongside the amount is what lets a payslip answer "why
 * this number?" after the schedule that produced it has been amended.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $payslip_id
 * @property string $kind
 * @property string $code
 * @property string $label
 * @property int $amount
 * @property string|null $rate
 * @property int $sort_order
 */
final class PayslipLine extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const KIND_EARNING = PayslipLineDraft::KIND_EARNING;

    public const KIND_DEDUCTION = PayslipLineDraft::KIND_DEDUCTION;

    public const KIND_CONTRIBUTION = PayslipLineDraft::KIND_CONTRIBUTION;

    public const KINDS = PayslipLineDraft::KINDS;

    /** @var list<string> */
    protected $fillable = [
        'workspace_id', 'payslip_id', 'kind', 'code', 'label', 'amount', 'rate', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'rate' => 'string',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Payslip, $this> */
    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function money(string $currency): Money
    {
        return Money::of($this->amount, $currency);
    }

    /**
     * @param  Builder<PayslipLine>  $query
     * @return Builder<PayslipLine>
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }
}
