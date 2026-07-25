<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollTaxRule;
use Modules\Payroll\Support\TaxRuleSet;

/**
 * Finds the schedule that prices a payslip.
 *
 * Order, most specific first:
 *   1. the workspace's own row for that country, in force on the date;
 *   2. the config entry for that country;
 *   3. the config 'default' entry.
 *
 * Nothing here knows any country's law — it knows where to look one up. That
 * is the whole point: docs/02-modules.md says payroll tax is country
 * dependent, so a country is data and adding one is a row or a config entry.
 */
final readonly class ResolveTaxRules
{
    public function handle(string $country, DateTimeInterface|string $on): TaxRuleSet
    {
        $country = strtoupper(trim($country));
        $date = CarbonImmutable::parse($on)->toDateString();

        $stored = PayrollTaxRule::query()->inForceFor($country, $date)->first();

        if ($stored !== null) {
            return $stored->toRuleSet();
        }

        $configured = $this->fromConfig($country);

        if ($configured !== null) {
            return TaxRuleSet::fromArray($configured, $country);
        }

        $fallback = $this->fromConfig('default');

        if ($fallback === null) {
            throw PayrollException::taxRulesNotFound($country);
        }

        return TaxRuleSet::fromArray($fallback, $country);
    }

    /** @return array<string, mixed>|null */
    private function fromConfig(string $key): ?array
    {
        $sets = config('payroll.rule_sets');

        if (! is_array($sets)) {
            return null;
        }

        $rules = $sets[$key] ?? null;

        return is_array($rules) ? $rules : null;
    }
}
