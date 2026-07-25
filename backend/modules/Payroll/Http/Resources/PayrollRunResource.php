<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Http\Concerns\PresentsMoney;
use Modules\Payroll\Models\PayrollRun;

/**
 * @mixin PayrollRun
 */
final class PayrollRunResource extends JsonResource
{
    use PresentsMoney;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,

            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'pay_date' => $this->pay_date?->toDateString(),

            'gross' => $this->presentMoney($this->gross()),
            'deductions' => $this->presentMoney($this->deductions()),
            'contributions' => $this->presentMoney($this->contributions()),
            'net' => $this->presentMoney($this->net()),
            'employer_cost' => $this->presentMoney($this->employerCost()),

            'account_id' => $this->account_id,
            'category_id' => $this->category_id,

            // A draft posts nothing, and says so.
            'is_posted' => $this->isPosted(),
            'net_transaction_id' => $this->net_transaction_id,
            'liability_transaction_id' => $this->liability_transaction_id,

            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'paid_at' => $this->paid_at?->toIso8601String(),

            'payslip_count' => $this->whenLoaded('payslips', fn () => $this->payslips->count()),
            'payslips' => PayslipResource::collection($this->whenLoaded('payslips')),

            'notes' => $this->notes,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
