<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payroll\Models\PayrollTaxRule;

/**
 * @mixin PayrollTaxRule
 */
final class TaxRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country' => $this->country,
            'name' => $this->name,
            'currency' => $this->currency,
            'effective_from' => $this->effective_from->toDateString(),
            'is_active' => $this->is_active,
            'rules' => $this->rules,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
