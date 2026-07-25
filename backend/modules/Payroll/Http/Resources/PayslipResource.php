<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Http\Concerns\PresentsMoney;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

/**
 * @mixin Payslip
 */
final class PayslipResource extends JsonResource
{
    use PresentsMoney;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = $this->currency;

        return [
            'id' => $this->id,
            'payroll_run_id' => $this->payroll_run_id,
            'employee_id' => $this->employee_id,

            'gross' => $this->presentMoney($this->grossMoney()),
            'deductions' => $this->presentMoney($this->deductionsMoney()),
            'contributions' => $this->presentMoney($this->contributionsMoney()),
            'net' => $this->presentMoney($this->netMoney()),
            'employer_cost' => $this->presentMoney($this->employerCost()),

            'period_days' => $this->period_days,
            'worked_days' => $this->worked_days,
            'is_prorated' => $this->isProrated(),

            'country' => $this->country,
            'tax_rules_name' => $this->tax_rules_name,

            'employee' => $this->whenLoaded('employee', fn () => $this->employee === null ? null : [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'job_title' => $this->employee->job_title,
            ]),

            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(
                fn (PayslipLine $line) => [
                    'id' => $line->id,
                    'kind' => $line->kind,
                    'code' => $line->code,
                    'label' => $line->label,
                    'amount' => $this->presentAmount($line->amount, $currency),
                    'rate' => $line->rate,
                    'sort_order' => $line->sort_order,
                ],
            )->all()),

            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
