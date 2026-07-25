<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use Carbon\CarbonImmutable;
use Modules\Core\Support\WorkspaceContext;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollTaxRule;
use Modules\Payroll\Support\TaxRuleSet;

/**
 * Stores a country's schedule for this workspace.
 *
 * The rules are parsed into a TaxRuleSet before they are saved, so a schedule
 * with descending brackets or a negative rate is refused at the door rather
 * than at the moment somebody's wages are being calculated.
 */
final readonly class StoreTaxRuleSet
{
    public function __construct(private WorkspaceContext $context) {}

    /**
     * @param  array{
     *   id?: string,
     *   country: string,
     *   name?: string|null,
     *   currency?: string|null,
     *   effective_from?: string|null,
     *   is_active?: bool|null,
     *   rules: array<string, mixed>,
     * }  $data
     */
    public function handle(array $data): PayrollTaxRule
    {
        $workspace = $this->context->require();
        $country = strtoupper(trim($data['country']));

        $effectiveFrom = isset($data['effective_from'])
            ? CarbonImmutable::parse($data['effective_from'])->startOfDay()
            : CarbonImmutable::now()->startOfYear();

        $rules = $data['rules'];
        $name = $data['name'] ?? null;
        $currency = isset($data['currency'])
            ? strtoupper($data['currency'])
            : null;

        // Parsing is the validation: if it cannot become a rule set it does not
        // become a row.
        $parsed = TaxRuleSet::fromArray(
            array_merge($rules, array_filter([
                'name' => $name,
                'currency' => $currency,
            ], fn (?string $value) => $value !== null)),
            $country,
            TaxRuleSet::SOURCE_WORKSPACE,
        );

        $exists = PayrollTaxRule::query()
            ->where('country', $country)
            ->whereDate('effective_from', $effectiveFrom->toDateString())
            ->exists();

        if ($exists) {
            throw PayrollException::duplicateTaxRules($country, $effectiveFrom->toDateString());
        }

        $rule = new PayrollTaxRule;

        if (! empty($data['id'])) {
            $rule->id = $data['id'];
        }

        $rule->fill([
            'workspace_id' => $workspace->id,
            'country' => $country,
            'name' => $parsed->name,
            'currency' => $parsed->currency,
            'effective_from' => $effectiveFrom,
            'is_active' => $data['is_active'] ?? true,
            'rules' => $parsed->toArray(),
        ]);

        $rule->save();

        return $rule;
    }
}
