<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Http\Concerns\PresentsMoney;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Models\EmployeeCompensation;

/**
 * @mixin Employee
 */
final class EmployeeResource extends JsonResource
{
    use PresentsMoney;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $current = $this->compensationOn($this->ended_on ?? now());

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'name' => $this->name,
            'job_title' => $this->job_title,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'status' => $this->status,
            'has_ended' => $this->hasEnded(),

            'started_on' => $this->started_on->toDateString(),
            'ended_on' => $this->ended_on?->toDateString(),

            // The national identifier is encrypted at rest and never leaves the
            // server: docs/07-security.md, data minimisation.
            'has_national_id' => $this->national_id !== null,

            'compensation' => $current === null ? null : $this->presentCompensation($current),

            'compensations' => $this->whenLoaded('compensations', fn () => $this->compensations->map(
                fn (EmployeeCompensation $row) => $this->presentCompensation($row),
            )->all()),

            'notes' => $this->notes,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentCompensation(EmployeeCompensation $row): array
    {
        return [
            'id' => $row->id,
            'amount' => $this->presentMoney($row->money()),
            'period' => $row->period,
            'effective_from' => $row->effective_from->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
        ];
    }
}
