<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Country
    |--------------------------------------------------------------------------
    |
    | Payroll tax is country law, and this product is not shipped per country.
    | So the calculation path reads a *rule set* — brackets and contributions
    | as data — and never a country's law written into PHP.
    |
    | A workspace stores its own rule sets in `payroll_tax_rules`; the ones
    | below are only the fallback when it has stored none. 'XX' is the ISO 3166
    | user-assigned code and means "no country stated yet".
    |
    */

    'default_country' => env('PAYROLL_DEFAULT_COUNTRY', 'XX'),

    /*
    |--------------------------------------------------------------------------
    | Fallback rule sets
    |--------------------------------------------------------------------------
    |
    | Keyed by uppercase ISO 3166-1 alpha-2 code, plus a 'default' entry used
    | when the country has no entry of its own. Adding a country is a config
    | entry or a database row — never a code change.
    |
    | `brackets`      progressive: each rate applies only to the part of the
    |                 taxable base between the previous ceiling and its own.
    |                 `up_to` is in minor units; the last one is null = no
    |                 ceiling.
    | `tax_base`      'gross' or 'gross_less_employee_contributions'.
    | `currency`      null means "applies whatever the run is denominated in";
    |                 set it and a payslip in another currency is refused,
    |                 because the bracket ceilings would then be meaningless.
    | `cap`           optional ceiling, in minor units, on the amount a
    |                 contribution may reach.
    |
    | The numbers below are deliberately round and belong to no real country.
    | They exist so a fresh workspace produces a deterministic payslip rather
    | than an error, and are meant to be replaced.
    |
    */

    'rule_sets' => [

        'default' => [
            'name' => 'Generic progressive schedule',
            'currency' => null,
            'tax_base' => 'gross_less_employee_contributions',

            'brackets' => [
                ['up_to' => 1000000, 'rate' => '10'],
                ['up_to' => 3000000, 'rate' => '20'],
                ['up_to' => null, 'rate' => '30'],
            ],

            'employee_contributions' => [
                ['code' => 'social_security', 'label' => 'Social security', 'rate' => '7.5', 'cap' => null],
            ],

            'employer_contributions' => [
                ['code' => 'employer_social_security', 'label' => 'Employer social security', 'rate' => '12.5', 'cap' => null],
            ],

            'fixed_deductions' => [
                ['code' => 'stamp_duty', 'label' => 'Stamp duty', 'amount' => 500],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Run reference
    |--------------------------------------------------------------------------
    |
    | Tokens: {prefix} {year} {month}. A second run in the same month gets
    | "-2", "-3" appended, so a bonus run never collides with the salary run.
    |
    */

    'run_reference' => [
        'format' => '{prefix}-{year}-{month}',
        'prefix' => 'PAY',
    ],

];
