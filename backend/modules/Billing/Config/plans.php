<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default plan
    |--------------------------------------------------------------------------
    |
    | What a workspace is entitled to when it has no subscription at all, and
    | what an expired trial or a lapsed subscription falls back to. It must
    | always be the least capable plan: everything degrades towards it.
    |
    */

    'default_plan' => 'free',

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    'gateway' => env('BILLING_GATEWAY', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    |
    | The source of truth for what a plan allows. The `plans` table is a seeded
    | projection of this array so invoices can reference a row and the client
    | can list prices, but no entitlement decision ever reads that table — a
    | catalogue row edited in production must not be able to widen a limit.
    |
    | `price` is an integer number of minor units, like every other amount in
    | the system. `limits` are counts, where null means unlimited.
    |
    | Order matters: a plan later in this list ranks above an earlier one, and
    | that ranking is what tells an upgrade from a downgrade.
    |
    */

    'plans' => [

        'free' => [
            'name' => 'Free',
            'price' => 0,
            'currency' => 'USD',
            'interval' => 'monthly',
            'limits' => [
                'workspaces' => 1,
                'accounts' => 2,
                'members' => 1,
                'budgets' => 2,
            ],
            'flags' => [
                'ai' => false,
                'ocr' => false,
                'voice' => false,
                'investment' => false,
                'shared_budget' => false,
                'invoicing' => false,
                'payroll' => false,
                'roles' => false,
                'sso' => false,
                'advanced_audit' => false,
                'sla' => false,
            ],
        ],

        'premium' => [
            'name' => 'Premium',
            'price' => 9_99,
            'currency' => 'USD',
            'interval' => 'monthly',
            'limits' => [
                'workspaces' => null,
                'accounts' => null,
                'members' => 1,
                'budgets' => null,
            ],
            'flags' => [
                'ai' => true,
                'ocr' => true,
                'voice' => true,
                'investment' => true,
                'shared_budget' => false,
                'invoicing' => false,
                'payroll' => false,
                'roles' => false,
                'sso' => false,
                'advanced_audit' => false,
                'sla' => false,
            ],
        ],

        'family' => [
            'name' => 'Family',
            'price' => 14_99,
            'currency' => 'USD',
            'interval' => 'monthly',
            'limits' => [
                'workspaces' => null,
                'accounts' => null,
                'members' => 5,
                'budgets' => null,
            ],
            'flags' => [
                'ai' => true,
                'ocr' => true,
                'voice' => true,
                'investment' => true,
                'shared_budget' => true,
                'invoicing' => false,
                'payroll' => false,
                'roles' => false,
                'sso' => false,
                'advanced_audit' => false,
                'sla' => false,
            ],
        ],

        'business' => [
            'name' => 'Business',
            'price' => 39_99,
            'currency' => 'USD',
            'interval' => 'monthly',
            'limits' => [
                'workspaces' => null,
                'accounts' => null,
                'members' => 25,
                'budgets' => null,
            ],
            'flags' => [
                'ai' => true,
                'ocr' => true,
                'voice' => true,
                'investment' => true,
                'shared_budget' => true,
                'invoicing' => true,
                'payroll' => true,
                'roles' => true,
                'sso' => false,
                'advanced_audit' => false,
                'sla' => false,
            ],
        ],

        'enterprise' => [
            'name' => 'Enterprise',
            'price' => 199_99,
            'currency' => 'USD',
            'interval' => 'yearly',
            'limits' => [
                'workspaces' => null,
                'accounts' => null,
                'members' => null,
                'budgets' => null,
            ],
            'flags' => [
                'ai' => true,
                'ocr' => true,
                'voice' => true,
                'investment' => true,
                'shared_budget' => true,
                'invoicing' => true,
                'payroll' => true,
                'roles' => true,
                'sso' => true,
                'advanced_audit' => true,
                'sla' => true,
            ],
        ],

    ],

];
