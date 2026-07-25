<?php

declare(strict_types=1);

/**
 * Component schemas.
 *
 * Field names here are transcribed from the API Resources and the inline
 * `present()` methods in backend/modules — not from the data model document,
 * which describes columns rather than payloads. Where the wire shape is
 * inconsistent between modules (and it is, in three places) the inconsistency
 * is documented rather than smoothed over, because a client written against a
 * smoothed-over spec breaks on the module that does it differently.
 */
$str = fn (string $d = '', array $x = []): array => array_merge(['type' => 'string'], $d === '' ? [] : ['description' => $d], $x);
$int = fn (string $d = '', array $x = []): array => array_merge(['type' => 'integer'], $d === '' ? [] : ['description' => $d], $x);
$num = fn (string $d = '', array $x = []): array => array_merge(['type' => 'number'], $d === '' ? [] : ['description' => $d], $x);
$bool = fn (string $d = '', array $x = []): array => array_merge(['type' => 'boolean'], $d === '' ? [] : ['description' => $d], $x);
$obj = fn (array $props, array $required = [], string $d = ''): array => array_filter([
    'type' => 'object',
    'description' => $d === '' ? null : $d,
    'required' => $required === [] ? null : $required,
    'properties' => $props,
], fn ($v) => $v !== null);
$arr = fn (array $items, string $d = ''): array => array_filter([
    'type' => 'array',
    'description' => $d === '' ? null : $d,
    'items' => $items,
]);
$ref = fn (string $name): array => ['$ref' => '#/components/schemas/'.$name];
$nul = fn (array $schema): array => array_merge($schema, [
    'type' => is_array($schema['type'] ?? 'string') ? $schema['type'] : [$schema['type'] ?? 'string', 'null'],
]);
$ulid = fn (string $d = 'ULID identifier.'): array => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26, 'description' => $d];
$dt = fn (string $d = ''): array => array_filter(['type' => ['string', 'null'], 'format' => 'date-time', 'description' => $d === '' ? null : $d]);
$date = fn (string $d = ''): array => array_filter(['type' => ['string', 'null'], 'format' => 'date', 'description' => $d === '' ? null : $d]);
$enum = fn (array $values, string $d = ''): array => array_filter(['type' => 'string', 'enum' => $values, 'description' => $d === '' ? null : $d]);

$currencies = ['IRR', 'IRT', 'TRY', 'USD', 'EUR', 'AED', 'BTC', 'ETH', 'USDT', 'XAU'];

/** A `{ "data": X }` envelope. */
$env = fn (array $schema): array => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $schema]];
/** A `{ "data": [X] }` envelope. */
$envList = fn (array $schema): array => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => $schema]]];
/** A `{ "data": [X], "meta": {page, per_page, total} }` envelope. */
$envPaged = fn (array $schema) => [
    'type' => 'object',
    'required' => ['data', 'meta'],
    'properties' => [
        'data' => ['type' => 'array', 'items' => $schema],
        'meta' => ['$ref' => '#/components/schemas/PageMeta'],
    ],
];

return [

    // ------------------------------------------------------------------ money

    'Money' => [
        'type' => 'object',
        'title' => 'Money',
        'description' => "An exact monetary amount.\n\n"
            ."`value` is an INTEGER count of the currency's minor units — never a decimal, never "
            .'a float. `minor_unit` is how many decimal places that currency has, and it is what '
            ."makes `value` interpretable:\n\n"
            ."- `{\"value\": 35000, \"currency\": \"TRY\", \"minor_unit\": 2}` is **₺350.00**\n"
            ."- `{\"value\": 35000, \"currency\": \"IRR\", \"minor_unit\": 0}` is **35,000 rials**\n"
            ."- `{\"value\": 35000, \"currency\": \"BTC\", \"minor_unit\": 8}` is **0.00035 BTC**\n\n"
            .'`decimal` is a rendering of the same number as a string. It exists so a client can '
            .'display an amount without reimplementing the scaling. It is DISPLAY ONLY — parsing '
            .'it back into a number, or computing with it, defeats the reason the value is an '
            ."integer in the first place.\n\n"
            .'This is the shape used in responses. In REQUESTS, amounts are sent as a bare integer '
            .'field (usually `amount`) with the currency in a sibling `currency` field.',
        'required' => ['value', 'currency', 'minor_unit', 'decimal'],
        'properties' => [
            'value' => $int('Integer amount in minor units.', ['examples' => [35000]]),
            'currency' => $enum($currencies, 'ISO-4217-style currency code.'),
            'minor_unit' => $int('Decimal places for this currency: 0 for IRR/IRT, 2 for TRY/USD/EUR/AED/USDT, 4 for XAU, 8 for BTC/ETH. Do not hard-code 2.', ['examples' => [2]]),
            'decimal' => $str('Display-only major-unit rendering. Never compute with this.', ['examples' => ['350.00']]),
        ],
    ],

    'MoneyBrief' => [
        'type' => 'object',
        'title' => 'Money (without decimal)',
        'description' => 'The same amount as `Money`, but without the `decimal` convenience '
            .'string. Emitted by a handful of aggregate `meta` blocks — `GET /accounts` '
            .'`meta.total`, `GET /investments` `meta.current_value`/`meta.total_profit`, and '
            .'`GET /assets` `meta.total_value`. Interpret `value` exactly as in `Money`.',
        'required' => ['value', 'currency', 'minor_unit'],
        'properties' => [
            'value' => $int('Integer amount in minor units.'),
            'currency' => $enum($currencies),
            'minor_unit' => $int('Decimal places for this currency.'),
        ],
    ],

    'MoneyWithRate' => [
        'type' => 'object',
        'title' => 'Money converted to the workspace base currency',
        'description' => 'A `Money` object plus the exchange rate used to reach it. Emitted as the '
            .'`base` field on records that were entered in a currency other than the workspace '
            .'base. The rate is recorded rather than recomputed, so a historical record keeps the '
            .'rate it was actually converted at.',
        'required' => ['value', 'currency', 'minor_unit', 'decimal'],
        'properties' => [
            'value' => $int('Integer amount in minor units of the base currency.'),
            'currency' => $enum($currencies, 'The workspace base currency.'),
            'minor_unit' => $int(),
            'decimal' => $str('Display only.'),
            'fx_rate' => ['type' => ['string', 'number', 'null'], 'description' => 'Rate applied, quoted in major units: one unit of the source currency buys this many of the target.'],
        ],
    ],

    'BillingMoney' => [
        'type' => 'object',
        'title' => 'Money (Billing module key naming)',
        'description' => '**Naming inconsistency, documented rather than hidden.** The Billing '
            .'module serialises `App\\Core\\Money\\Money` directly, which names the integer field '
            .'`amount`. Every other module wraps it in a Resource that renames the field to '
            .'`value`. Same meaning, same units — different key. Applies to `Plan.price` and '
            .'`SubscriptionInvoice.amount`.',
        'required' => ['amount', 'currency', 'minor_unit', 'decimal'],
        'properties' => [
            'amount' => $int('Integer amount in minor units. Called `value` everywhere else.'),
            'currency' => $enum($currencies),
            'minor_unit' => $int(),
            'decimal' => $str('Display only.'),
        ],
    ],

    // ----------------------------------------------------------------- errors

    'Error' => [
        'type' => 'object',
        'title' => 'Error envelope',
        'description' => 'The envelope used by domain refusals. `code` is stable, English and '
            .'machine-readable; clients translate from it. `message` is developer-facing prose and '
            .'must not be shown to a user.',
        'required' => ['error'],
        'properties' => [
            'error' => [
                'type' => 'object',
                'required' => ['code', 'message'],
                'properties' => [
                    'code' => $str('Stable machine-readable identifier. Always English.'),
                    'message' => $str('Developer-facing explanation. Not for display to end users.'),
                    'details' => ['type' => ['object', 'null'], 'additionalProperties' => true, 'description' => 'Context for the refusal, e.g. `{"given": "USD", "expected": "TRY"}`. Absent on some refusals.'],
                    'request_id' => ['type' => ['string', 'null'], 'description' => 'Echo of the `X-Request-Id` header, or null if none was sent.'],
                ],
            ],
        ],
    ],

    'ValidationError' => [
        'type' => 'object',
        'title' => 'Framework validation error',
        'description' => "Laravel's own validation response. This is what a `422` looks like when "
            .'the request failed field validation, and it does NOT carry an error `code`. A client '
            .'must handle this shape as well as `Error`.',
        'required' => ['message', 'errors'],
        'properties' => [
            'message' => $str('Summary of the first failure.'),
            'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => 'Field name to list of failure messages.'],
        ],
    ],

    'FrameworkError' => [
        'type' => 'object',
        'title' => 'Framework error',
        'description' => "Laravel's default error body, returned by bare permission aborts and by "
            .'`findOrFail` misses. Carries no `code`.',
        'required' => ['message'],
        'properties' => ['message' => $str()],
    ],

    'PageMeta' => [
        'type' => 'object',
        'title' => 'Pagination meta',
        'required' => ['page', 'per_page', 'total'],
        'properties' => [
            'page' => $int('1-based page number.'),
            'per_page' => $int('Page size. Defaults to 50, capped at 200.'),
            'total' => $int('Total matching records.'),
        ],
    ],

    // ----------------------------------------------------------------- Ledger

    'Account' => $obj([
        'id' => $ulid(),
        'name' => $str(),
        'type' => $enum(['cash', 'bank', 'card', 'wallet', 'fund', 'petty_cash', 'crypto', 'gold', 'fx']),
        'currency' => $enum($currencies),
        'balance' => $ref('Money'),
        'opening_balance' => $int('Bare integer in minor units of `currency` — not a `Money` object.'),
        'iban' => ['type' => ['string', 'null'], 'description' => 'Encrypted at rest; returned in the clear to members.'],
        'card_last4' => ['type' => ['string', 'null']],
        'icon' => ['type' => ['string', 'null']],
        'color' => ['type' => ['string', 'null']],
        'is_archived' => $bool(),
        'version' => $int('Optimistic-concurrency counter. Sent back as `base_version` when syncing.'),
    ], ['id', 'name', 'type', 'currency', 'balance'], 'A place money sits.'),

    'Category' => $obj([
        'id' => $ulid(),
        'parent_id' => ['type' => ['string', 'null']],
        'name' => $str(),
        'name_key' => ['type' => ['string', 'null'], 'description' => 'Translation key for system categories, so a seeded category renders in the user\'s language.'],
        'path' => $str('Materialised path, which is what makes a subtree query one indexed comparison.'),
        'depth' => $int(),
        'type' => $enum(['income', 'expense', 'both']),
        'icon' => ['type' => ['string', 'null']],
        'color' => ['type' => ['string', 'null']],
        'is_system' => $bool('Seeded on registration; not user-created.'),
        'children' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Category'], 'description' => 'Present only when the list was requested with `tree=1`.'],
    ], ['id', 'name', 'path', 'type'], 'An unlimited-depth spending or income category.'),

    'Transaction' => $obj([
        'id' => $ulid(),
        'type' => $enum(['income', 'expense', 'transfer']),
        'amount' => $ref('Money'),
        'base' => $ref('MoneyWithRate'),
        'account_id' => $ulid(),
        'counter_account_id' => ['type' => ['string', 'null'], 'description' => 'Destination account. Required for, and only meaningful on, a transfer.'],
        'category_id' => ['type' => ['string', 'null']],
        'account' => $obj([
            'id' => $ulid(), 'name' => $str(), 'type' => $str(), 'currency' => $str(),
        ], [], 'Included only when the relation was loaded.'),
        'category' => ['type' => ['object', 'null'], 'description' => 'Included only when the relation was loaded.', 'properties' => [
            'id' => $ulid(), 'name' => $str(), 'path' => $str(), 'color' => ['type' => ['string', 'null']], 'icon' => ['type' => ['string', 'null']],
        ]],
        'entries' => $arr($obj([
            'id' => $ulid(),
            'account_id' => $ulid(),
            'direction' => $enum(['debit', 'credit']),
            'amount' => $int('Bare integer in minor units — the entry rows are NOT `Money` objects.'),
            'currency' => $enum($currencies),
            'base_amount' => $int('Bare integer in minor units of the workspace base currency.'),
        ]), 'The double-entry rows. Included only when the relation was loaded (i.e. on show, not on list). Debits and credits sum to zero for every transaction — an imbalance is a `500 unbalanced_transaction`, not a client error.'),
        'occurred_at' => $dt('When the money moved, which is not necessarily when the record was created.'),
        'description' => ['type' => ['string', 'null']],
        'notes' => ['type' => ['string', 'null']],
        'payee' => ['type' => ['string', 'null']],
        'reference' => ['type' => ['string', 'null']],
        'tags' => $arr($str()),
        'source' => $enum(['manual', 'voice', 'ocr', 'sms', 'email', 'qr', 'import', 'recurring', 'api'], 'How the record entered the system.'),
        'is_reconciled' => $bool(),
        'version' => $int(),
        'created_at' => $dt(),
        'updated_at' => $dt(),
    ], ['id', 'type', 'amount', 'account_id'], 'A double-entry transaction. Posting one writes balanced entry rows inside a single database transaction.'),

    // ----------------------------------------------------------------- Budget

    'Budget' => $obj([
        'id' => $ulid(),
        'name' => $str(),
        'scope' => $enum(['overall', 'category', 'project', 'trip', 'building', 'member']),
        'scope_id' => ['type' => ['string', 'null'], 'description' => 'The scoped record. Null when `scope` is `overall`, and forced to null by the server in that case.'],
        'period' => $enum(['monthly', 'yearly', 'custom']),
        'starts_at' => $dt(),
        'ends_at' => $dt('Required when `period` is `custom`.'),
        'amount' => $ref('Money'),
        'rollover' => $bool('Carry an unspent remainder into the next period.'),
        'alert_thresholds' => $arr($int(), 'Percentages at which an alert fires, e.g. `[80, 100]`.'),
        'version' => $int(),
        'created_at' => $dt(),
        'updated_at' => $dt(),
    ], ['id', 'name', 'scope', 'period', 'amount']),

    'BudgetStatus' => $obj([
        'id' => $ulid(),
        'name' => $str(),
        'scope' => $str(),
        'scope_id' => ['type' => ['string', 'null']],
        'period' => $str(),
        'period_key' => $str('Identifier of the period being reported, e.g. `2026-07`.'),
        'period_start' => $dt(),
        'period_end' => $dt(),
        'amount' => $ref('Money'),
        'effective_amount' => ['allOf' => [['$ref' => '#/components/schemas/Money']], 'description' => 'Budget for the period including any rollover carried in.'],
        'spent' => $ref('Money'),
        'remaining' => $ref('Money'),
        'percentage' => $num('Share of the effective amount spent, to two decimal places.'),
        'rollover' => $bool(),
        'alert_thresholds' => $arr($int()),
        'thresholds_crossed' => $arr($int(), 'Which of the configured thresholds this period has already passed.'),
        'is_over_budget' => $bool(),
    ], ['id', 'name', 'amount', 'spent', 'remaining'], 'Live budget consumption. A different shape from `Budget` — this one is computed, not stored.'),

    // ---------------------------------------------------------------- Reports

    'ReportMeta' => $obj([
        'from' => $date(),
        'to' => $date(),
        'bucket' => ['type' => ['string', 'null'], 'enum' => ['day', 'week', 'month', 'year', null]],
        'currency' => $enum($currencies, 'Reports are stated in the workspace base currency.'),
        'boundaries' => $str('Literal statement of how the range is closed, so a reader never has to guess whether `to` is inclusive.'),
        'type' => ['type' => ['string', 'null'], 'description' => 'On the `top-*` and `category-breakdown` reports this is the FLOW (`income` or `expense`), not the report name.'],
        'depth' => ['type' => ['integer', 'null']],
        'limit' => ['type' => ['integer', 'null']],
    ], [], 'Common meta block on every report. Null keys are stripped, so absent means not applicable to that report.'),

    'Report' => [
        'type' => 'object',
        'title' => 'Report payload',
        'description' => 'Shape varies by report type. Every payload carries `report` (the type) '
            ."and `meta`; the rest depends:\n\n"
            ."- `cash-flow` — `periods[]` of `{key, start, end, income, expense, net, transaction_count}` and `totals`\n"
            ."- `net-worth` — `total`, `accounts[]` of `{account_id, name, type, balance, base_balance, archived}`, `series[]` of `{key, start, end, net_worth}`\n"
            ."- `expense-trend` / `income-trend` — `periods[]` of `{key, start, end, total, transaction_count}` and `totals` including `average_per_period`\n"
            ."- `top-categories` — `categories[]` of `{rank, category_id, path, name, depth, total, transaction_count, percentage}`, plus `other` and `totals`\n"
            ."- `top-merchants` — `merchants[]` of `{rank, payee, total, transaction_count, percentage}`, plus `unlabelled` and `totals`\n"
            ."- `top-accounts` — `accounts[]` of `{rank, account_id, name, type, currency, total, transaction_count, percentage}` and `totals`\n"
            ."- `category-breakdown` — `slices[]` of `{category_id, path, name, depth, total, transaction_count, percentage}` and `totals`; a trailing remainder slice has `category_id: null` and `name: \"other\"`\n\n"
            .'Every `total`, `income`, `expense`, `net` and `net_worth` field is a `Money` object. '
            .'Transfers are excluded from every report — moving your own money between your own '
            .'accounts is not income and not spending.',
        'required' => ['report', 'meta'],
        'properties' => [
            'report' => $enum(['cash-flow', 'net-worth', 'expense-trend', 'income-trend', 'top-categories', 'top-merchants', 'top-accounts', 'category-breakdown']),
            'meta' => $ref('ReportMeta'),
        ],
        'additionalProperties' => true,
    ],

    'ReportExport' => $obj([
        'id' => $ulid(),
        'report' => $str('The report type this export was produced from.'),
        'format' => $enum(['csv', 'xlsx', 'pdf']),
        'locale' => $enum(['fa', 'en', 'tr', 'ar'], 'Language of the FILE, chosen separately from the app language.'),
        'filters' => $obj(['from' => $date(), 'to' => $date(), 'bucket' => $str(), 'limit' => $int(), 'depth' => $int(), 'flow' => $str()]),
        'status' => $enum(['pending', 'processing', 'ready', 'failed']),
        'filename' => ['type' => ['string', 'null']],
        'size' => ['type' => ['integer', 'null'], 'description' => 'Bytes.'],
        'row_count' => ['type' => ['integer', 'null']],
        'error' => ['type' => ['string', 'null']],
        'created_at' => $dt(),
        'completed_at' => $dt(),
        'download_url' => ['type' => ['string', 'null'], 'description' => 'Absolute URL, or null until the export is ready.'],
    ], ['id', 'report', 'format', 'status']),

    // ----------------------------------------------------------------- Search

    'SearchHit' => $obj([
        'type' => $enum(['transactions', 'documents', 'categories']),
        'id' => $ulid(),
        'score' => $num('Combined rank, 6 decimal places.'),
        'semantic_score' => $num(),
        'lexical_score' => $num(),
        'record' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'The matched record. For a transaction this is `{id, type, description, payee, notes, amount: {value, currency}, occurred_at, category}` — note that `amount` here carries ONLY `value` and `currency`, without `minor_unit` or `decimal`. For a document, `{id, original_name, kind, mime, size, ocr_status}`. For a category, `{id, name, path, type, depth}`.'],
    ], ['type', 'id', 'record']),

    // ---------------------------------------------------------------- Banking

    'Bank' => $obj([
        'id' => $ulid(), 'name' => $str(), 'branch' => ['type' => ['string', 'null']],
        'swift' => ['type' => ['string', 'null']], 'country' => ['type' => ['string', 'null'], 'description' => 'Two-letter code.'],
        'logo' => ['type' => ['string', 'null']], 'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'name']),

    'Check' => $obj([
        'id' => $ulid(),
        'account_id' => $ulid(),
        'direction' => $enum(['received', 'issued', 'guarantee']),
        'check_number' => $str(),
        'amount' => $ref('Money'),
        'base_amount' => ['type' => ['integer', 'null'], 'description' => 'Bare integer in minor units of the workspace base currency — not a `Money` object here.'],
        'due_date' => $date(),
        'status' => $enum(['draft', 'issued', 'in_progress', 'cleared', 'bounced', 'void']),
        'party_name' => ['type' => ['string', 'null']],
        'notes' => ['type' => ['string', 'null']],
        'transaction_id' => ['type' => ['string', 'null'], 'description' => 'The ledger transaction written when the cheque cleared.'],
        'cleared_at' => $dt(),
        'created_at' => $dt(),
        'updated_at' => $dt(),
    ], ['id', 'account_id', 'direction', 'check_number', 'amount', 'due_date', 'status']),

    'Loan' => $obj([
        'id' => $ulid(),
        'bank_id' => ['type' => ['string', 'null']],
        'account_id' => $ulid(),
        'title' => ['type' => ['string', 'null']],
        'principal' => $ref('Money'),
        'outstanding_balance' => $ref('Money'),
        'currency' => $enum($currencies),
        'interest_rate' => $num('Annual percentage.'),
        'interest_type' => $enum(['simple', 'compound']),
        'installments_count' => $int(),
        'start_date' => $date(),
        'penalty_rate' => ['type' => ['number', 'null']],
        'status' => $enum(['active', 'closed', 'defaulted']),
        'installments' => $arr($ref('LoanInstallment'), 'Included on create and show, omitted from the list.'),
        'created_at' => $dt(),
        'updated_at' => $dt(),
    ], ['id', 'account_id', 'principal', 'installments_count']),

    'LoanInstallment' => $obj([
        'id' => $ulid(),
        'loan_id' => $ulid(),
        'number' => $int('1-based position in the schedule.'),
        'due_date' => $date(),
        'principal_part' => $ref('Money'),
        'interest_part' => $ref('Money'),
        'total_amount' => $ref('Money'),
        'paid_amount' => $ref('Money'),
        'penalty_amount' => $ref('Money'),
        'remaining' => $ref('Money'),
        'paid_at' => $dt(),
        'status' => $enum(['due', 'paid', 'late', 'partial']),
        'transaction_id' => ['type' => ['string', 'null']],
    ], ['id', 'loan_id', 'number', 'total_amount'], 'One row of a generated schedule. The principal parts across a whole schedule sum to the loan principal exactly.'),

    // ------------------------------------------------------------- Investment

    'Investment' => $obj([
        'id' => $ulid(),
        'name' => $str(),
        'kind' => $enum(['gold', 'fx', 'stock', 'etf', 'crypto', 'real_estate', 'vehicle', 'startup']),
        'symbol' => ['type' => ['string', 'null']],
        'currency' => $enum($currencies),
        'quantity' => $str('Decimal STRING, up to 8 places. A string rather than a number so a fractional holding cannot be mangled by float parsing.'),
        'avg_buy_price' => $ref('Money'),
        'current_price' => ['oneOf' => [['$ref' => '#/components/schemas/Money'], ['type' => 'null']]],
        'cost_basis' => $ref('Money'),
        'current_value' => $ref('Money'),
        'realized_profit' => $ref('Money'),
        'unrealized_profit' => $ref('Money'),
        'total_profit' => $ref('Money'),
        'roi' => $num('Return on investment as a ratio.'),
        'priced_at' => $dt(),
        'notes' => ['type' => ['string', 'null']],
        'transactions' => $arr($ref('InvestmentTransaction'), 'Included only on show.'),
        'version' => $int(),
        'created_at' => $dt(),
        'updated_at' => $dt(),
    ], ['id', 'name', 'kind', 'currency', 'quantity'], 'A position. Weighted-average cost survives repeated buys and is never moved by a sell.'),

    'InvestmentTransaction' => $obj([
        'id' => $ulid(),
        'investment_id' => $ulid(),
        'action' => $enum(['buy', 'sell', 'dividend', 'fee', 'split']),
        'quantity' => $str('Decimal string.'),
        'price' => $ref('Money'),
        'fee' => $ref('Money'),
        'gross' => $ref('Money'),
        'realized_profit' => $ref('Money'),
        'base' => $ref('MoneyWithRate'),
        'occurred_at' => $dt(),
        'notes' => ['type' => ['string', 'null']],
        'transaction_id' => ['type' => ['string', 'null']],
        'created_at' => $dt(),
    ], ['id', 'investment_id', 'action']),

    // ----------------------------------------------------------------- Assets

    'Asset' => $obj([
        'id' => $ulid(),
        'name' => $str(),
        'kind' => $enum(['house', 'land', 'car', 'gold', 'watch', 'art', 'nft', 'other']),
        'currency' => $enum($currencies),
        'purchase_price' => $ref('Money'),
        'current_value' => $ref('Money'),
        'salvage_value' => $ref('Money'),
        'purchase_date' => $date(),
        'depreciation' => $obj([
            'method' => $enum(['none', 'linear', 'declining']),
            'rate' => ['type' => ['string', 'null'], 'description' => 'Declining-balance rate, strictly between 0 and 1.'],
            'useful_life_years' => ['type' => ['integer', 'null']],
        ]),
        'insurance' => $obj([
            'provider' => ['type' => ['string', 'null']],
            'expires_at' => $date(),
            'is_expired' => $bool(),
        ]),
        'notes' => ['type' => ['string', 'null']],
        'version' => $int(),
        'created_at' => $dt(),
        'updated_at' => $dt(),
    ], ['id', 'name', 'kind', 'currency', 'purchase_price']),

    'DepreciationRow' => $obj([
        'year' => $int(),
        'opening' => $ref('Money'),
        'depreciation' => $ref('Money'),
        'accumulated' => $ref('Money'),
        'closing' => $ref('Money'),
    ], ['year'], 'One year of a schedule. Over the full life the depreciation sums to `purchase_price - salvage_value` and never takes the book value below salvage.'),

    // -------------------------------------------------------------- Buildings

    'Building' => $obj([
        'id' => $ulid(), 'name' => $str(), 'address' => ['type' => ['string', 'null']],
        'units_count' => $int(),
        'charge_formula' => $enum(['fixed', 'per_area', 'per_resident', 'mixed'], 'How a period total is divided between units.'),
        'fund_account_id' => ['type' => ['string', 'null']],
        'fund_balance' => ['oneOf' => [['$ref' => '#/components/schemas/Money'], ['type' => 'null']]],
        'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'name', 'charge_formula']),

    'BuildingUnit' => $obj([
        'id' => $ulid(), 'building_id' => $ulid(), 'unit_no' => $str(),
        'area_m2' => ['type' => ['string', 'null'], 'description' => 'Decimal STRING. Areas are parsed digit by digit, never through a float, because a rounding error here is multiplied across every unit.'],
        'residents_count' => ['type' => ['integer', 'null']],
        'owner_name' => ['type' => ['string', 'null']], 'owner_contact' => ['type' => ['string', 'null']],
        'tenant_name' => ['type' => ['string', 'null']], 'tenant_contact' => ['type' => ['string', 'null']],
        'share_factor' => ['type' => ['string', 'null'], 'description' => 'Decimal string.'],
        'is_occupied' => $bool(), 'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'building_id', 'unit_no']),

    'BuildingCharge' => $obj([
        'id' => $ulid(), 'building_id' => $ulid(), 'unit_id' => $ulid(),
        'period' => $str('`YYYY-MM`.'),
        'amount' => $ref('Money'), 'paid' => $ref('Money'), 'remaining' => $ref('Money'),
        'due_date' => $date(),
        'status' => $enum(['unpaid', 'partial', 'paid']),
        'transaction_id' => ['type' => ['string', 'null']],
        'unit' => ['type' => ['object', 'null'], 'description' => 'Included when loaded.', 'properties' => [
            'id' => $ulid(), 'unit_no' => $str(), 'owner_name' => ['type' => ['string', 'null']], 'tenant_name' => ['type' => ['string', 'null']],
        ]],
        'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'building_id', 'unit_id', 'period', 'amount']),

    'BuildingExpense' => $obj([
        'id' => $ulid(), 'building_id' => $ulid(),
        'category_id' => ['type' => ['string', 'null']],
        'amount' => $ref('Money'), 'occurred_at' => $dt(),
        'description' => ['type' => ['string', 'null']],
        'transaction_id' => ['type' => ['string', 'null']],
    ], ['id', 'building_id', 'amount']),

    // --------------------------------------------------------------- Business

    'Contact' => $obj([
        'id' => $ulid(),
        'type' => $enum(['customer', 'supplier', 'employee', 'other']),
        'name' => $str(), 'phone' => ['type' => ['string', 'null']], 'email' => ['type' => ['string', 'null']],
        'tax_id' => ['type' => ['string', 'null']], 'address' => ['type' => ['string', 'null']],
        'version' => $int(), 'created_at' => $dt(),
    ], ['id', 'type', 'name']),

    'Project' => $obj([
        'id' => $ulid(), 'name' => $str(),
        'contact_id' => ['type' => ['string', 'null']],
        'status' => $enum(['planned', 'active', 'paused', 'completed', 'cancelled']),
        'budget' => $ref('Money'),
        'currency' => $enum($currencies),
        'starts_at' => $date(), 'ends_at' => $date(), 'version' => $int(),
    ], ['id', 'name', 'currency']),

    'Invoice' => $obj([
        'id' => $ulid(),
        'number' => ['type' => ['string', 'null'], 'description' => 'Allocated per workspace and direction; a duplicate is `409 duplicate_invoice_number`.'],
        'direction' => $enum(['sale', 'purchase']),
        'status' => $enum(['draft', 'sent', 'partial', 'paid', 'overdue', 'void']),
        'is_overdue' => $bool(),
        'contact_id' => ['type' => ['string', 'null']], 'project_id' => ['type' => ['string', 'null']],
        'issue_date' => $date(), 'due_date' => $date(),
        'subtotal' => $ref('Money'), 'discount' => $ref('Money'), 'tax' => $ref('Money'),
        'total' => $ref('Money'), 'paid' => $ref('Money'), 'outstanding' => $ref('Money'),
        'base' => $ref('MoneyWithRate'),
        'notes' => ['type' => ['string', 'null']],
        'contact' => ['type' => ['object', 'null'], 'properties' => ['id' => $ulid(), 'name' => $str(), 'type' => $str()]],
        'project' => ['type' => ['object', 'null'], 'properties' => ['id' => $ulid(), 'name' => $str(), 'status' => $str()]],
        'items' => $arr($ref('InvoiceItem')),
        'payments' => $arr($ref('Payment')),
        'version' => $int(), 'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'direction', 'status', 'total'], 'The invariants held here: the line totals sum to `subtotal`, and `subtotal - discount + tax == total`. A violation is `500 inconsistent_invoice_totals`, not a client error.'),

    'InvoiceItem' => $obj([
        'id' => $ulid(), 'description' => $str(),
        'quantity' => $str('Decimal string.'),
        'unit_price' => $ref('Money'), 'discount' => $ref('Money'),
        'tax_rate' => $num('Percentage, 0-100.'),
        'tax' => $ref('Money'), 'line_total' => $ref('Money'),
        'sort_order' => $int(),
    ], ['id', 'description', 'line_total']),

    'Payment' => $obj([
        'id' => $ulid(),
        'invoice_id' => ['type' => ['string', 'null']],
        'contact_id' => ['type' => ['string', 'null']],
        'account_id' => $ulid(),
        'amount' => $ref('Money'),
        'paid_at' => $dt(),
        'method' => $enum(['cash', 'bank', 'card', 'cheque', 'online', 'other']),
        'transaction_id' => ['type' => ['string', 'null']],
        'reference' => ['type' => ['string', 'null']],
        'created_at' => $dt(),
    ], ['id', 'account_id', 'amount']),

    // ----------------------------------------------------------------- Travel

    'Trip' => $obj([
        'id' => $ulid(), 'name' => $str(), 'destination' => ['type' => ['string', 'null']],
        'starts_at' => $dt(), 'ends_at' => $dt(),
        'base_currency' => $enum($currencies),
        'members' => $arr($ref('TripMember')),
        'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'name', 'base_currency']),

    'TripMember' => $obj([
        'id' => $ulid(), 'trip_id' => $ulid(),
        'user_id' => ['type' => ['integer', 'null'], 'description' => 'Set when the traveller is also a Finora user; a trip can include people who are not.'],
        'display_name' => $str(),
        'weight' => $int('Relative share when splitting by weight.'),
    ], ['id', 'trip_id', 'display_name']),

    'SplitExpense' => $obj([
        'id' => $ulid(), 'trip_id' => $ulid(), 'payer_member_id' => $ulid(),
        'amount' => $ref('Money'), 'base' => $ref('MoneyWithRate'),
        'category_id' => ['type' => ['string', 'null']],
        'occurred_at' => $dt(), 'description' => ['type' => ['string', 'null']],
        'latitude' => ['type' => ['number', 'null']], 'longitude' => ['type' => ['number', 'null']],
        'shares' => $arr($obj([
            'id' => $ulid(), 'member_id' => $ulid(),
            'mode' => $enum(['equal', 'percent', 'weight', 'exact']),
            'amount' => $ref('Money'), 'base' => $ref('Money'),
        ]), 'The per-person shares. They always sum to `amount` exactly — a remainder is distributed one minor unit at a time rather than being rounded away.'),
        'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'trip_id', 'payer_member_id', 'amount']),

    'Settlement' => $obj([
        'id' => $ulid(), 'trip_id' => $ulid(),
        'from_member_id' => $ulid(), 'to_member_id' => $ulid(),
        'amount' => $ref('Money'), 'settled_at' => $dt(),
        'transaction_id' => ['type' => ['string', 'null']],
    ], ['id', 'trip_id', 'from_member_id', 'to_member_id', 'amount']),

    // -------------------------------------------------------------- Documents

    'Document' => $obj([
        'id' => $ulid(), 'disk' => $str(), 'original_name' => $str(),
        'mime' => $str(), 'size' => $int('Bytes.'),
        'kind' => $enum(['receipt', 'invoice', 'contract', 'photo', 'voice', 'video', 'archive', 'other']),
        'checksum' => $str(),
        'ocr' => $obj([
            'status' => $enum(['pending', 'processing', 'done', 'failed', 'skipped']),
            'text' => ['type' => ['string', 'null']],
            'data' => ['type' => ['object', 'null'], 'additionalProperties' => true],
        ]),
        'uploaded_by' => ['type' => ['integer', 'null']],
        'attachments' => $arr($obj([
            'type' => $enum(['transaction', 'category', 'account'], 'Alias, not a PHP class name.'),
            'id' => $ulid(),
        ]), 'Included when loaded.'),
        'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'original_name', 'mime', 'size', 'kind']),

    // ----------------------------------------------------------------- Family

    'FamilyMember' => $obj([
        'id' => $ulid(),
        'user_id' => ['type' => ['integer', 'null']],
        'display_name' => $str(),
        'role' => $enum(['parent', 'child', 'other']),
        'birth_date' => $date(),
        'monthly_allowance' => ['oneOf' => [['$ref' => '#/components/schemas/Money'], ['type' => 'null']], 'description' => 'A `Money` object in responses; sent as a bare integer on create/update.'],
        'spending_cap' => ['oneOf' => [['$ref' => '#/components/schemas/Money'], ['type' => 'null']], 'description' => 'A `Money` object in responses; sent as a bare integer on create/update.'],
        'currency' => $enum($currencies),
        'account_id' => ['type' => ['string', 'null']],
        'tag' => ['type' => ['string', 'null']],
    ], ['id', 'display_name', 'role', 'currency']),

    'AllowancePayment' => $obj([
        'id' => $ulid(), 'member_id' => $ulid(), 'payer_member_id' => $ulid(),
        'period' => $str('`YYYY-MM`.'),
        'amount' => $ref('Money'),
        'transaction_id' => ['type' => ['string', 'null']],
        'paid_at' => $dt(),
    ], ['id', 'member_id', 'period', 'amount']),

    'MemberSpending' => $obj([
        'member_id' => $ulid(), 'display_name' => $str(), 'role' => $str(),
        'period' => $str('`YYYY-MM`.'),
        'spent' => $ref('Money'),
        'cap' => ['oneOf' => [['$ref' => '#/components/schemas/Money'], ['type' => 'null']]],
        'remaining' => ['oneOf' => [['$ref' => '#/components/schemas/Money'], ['type' => 'null']]],
        'percentage' => ['type' => ['number', 'null']],
        'is_over_cap' => $bool(),
    ], ['member_id', 'period', 'spent']),

    // -------------------------------------------------------------- Recurring

    'RecurringRule' => $obj([
        'id' => $ulid(),
        'name' => ['type' => ['string', 'null']],
        'template' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'The transaction to post each period, echoed back as stored. Note `template.amount` is a BARE INTEGER in minor units with `template.currency` beside it — not a `Money` object.'],
        'frequency' => $enum(['daily', 'weekly', 'monthly', 'yearly']),
        'interval' => $int('Every N periods.'),
        'day_of_month' => ['type' => ['integer', 'null']],
        'day_of_week' => ['type' => ['integer', 'null'], 'description' => '0-6.'],
        'starts_at' => $dt(), 'ends_at' => $dt(),
        'next_run_at' => $dt(), 'last_run_at' => $dt(),
        'auto_post' => $bool('Post automatically, or only propose.'),
        'is_paused' => $bool(),
    ], ['id', 'template', 'frequency', 'starts_at']),

    // --------------------------------------------------------------------- AI

    'AiDraft' => $obj([
        'id' => $ulid(),
        'kind' => $enum(['transaction', 'receipt']),
        'source' => $enum(['text', 'voice', 'ocr']),
        'status' => $enum(['pending', 'confirmed', 'discarded']),
        'confidence' => $num('0-1.'),
        'needs_confirmation' => $bool(),
        'warnings' => $arr($str()),
        'input_text' => ['type' => ['string', 'null']],
        'draft' => ['type' => ['object', 'null'], 'additionalProperties' => true, 'description' => 'The proposal. For `kind: transaction`: `{type, amount, currency, occurred_at, description, category_suggestion, account_suggestion, confidence, needs_confirmation, low_confidence, warnings, meta}` — `amount` is a bare integer in minor units. For `kind: receipt`: `{fields, items, arithmetic, confidence, needs_confirmation, low_confidence, warnings, transaction, raw_text}`.'],
        'transaction_id' => ['type' => ['string', 'null'], 'description' => 'Set once the draft has been confirmed into the ledger.'],
        'document_id' => ['type' => ['string', 'null']],
        'created_at' => $dt(), 'resolved_at' => $dt(),
    ], ['id', 'kind', 'source', 'status'], 'A proposal, never a posting. Nothing reaches the ledger until an explicit confirm call.'),

    'AiInsight' => $obj([
        'id' => $ulid(),
        'type' => $enum(['spending_composition', 'period_change', 'spending_anomaly', 'cashflow_forecast']),
        'severity' => $enum(['info', 'warning']),
        'body' => $str(),
        'data' => ['type' => ['object', 'null'], 'additionalProperties' => true],
        'score' => $num(),
        'period' => $obj(['from' => $date(), 'to' => $date()]),
        'computed_at' => $dt(), 'dismissed_at' => $dt(),
    ], ['id', 'type', 'severity', 'body']),

    // ---------------------------------------------------------------- Capture

    'CaptureMessage' => $obj([
        'id' => $ulid(),
        'channel' => $enum(['text', 'sms', 'qr', 'email']),
        'status' => $enum(['parsed', 'unparsed', 'duplicate', 'rejected'], 'An unrecognised message is `unparsed` and still answered `201` — it is kept for review, not thrown away.'),
        'sender' => ['type' => ['string', 'null']],
        'subject' => ['type' => ['string', 'null']],
        'received_at' => $dt(),
        'matched_pattern' => ['type' => ['string', 'null']],
        'reason' => ['type' => ['string', 'null']],
        'parsed' => ['type' => ['object', 'null'], 'additionalProperties' => true, 'description' => 'Structured extraction. For SMS: `{pattern, bank, direction, type, account_fragment, balance, matched, amount: {amount, currency, currency_explicit, matched, multiplier, toman_rial_factor}}`. For QR: `{format, type, amount, currency, currency_explicit, merchant, reference, occurred_at, fields}`.'],
        'duplicate_of_id' => ['type' => ['string', 'null']],
        'transaction_id' => ['type' => ['string', 'null']],
        'needs_confirmation' => $bool(),
        'draft' => ['oneOf' => [['$ref' => '#/components/schemas/AiDraft'], ['type' => 'null']]],
        'created_at' => $dt(),
    ], ['id', 'channel', 'status']),

    'IngestAlias' => $obj([
        'id' => $ulid(), 'domain' => $str(),
        'label' => ['type' => ['string', 'null']],
        'revoked' => $bool(),
        'last_message_at' => $dt(),
        'created_at' => $dt(),
        'address' => ['type' => ['string', 'null'], 'description' => 'The full ingest address. Returned ONLY from the create call — only a hash is stored, so it cannot be shown again.'],
    ], ['id', 'domain']),

    // ----------------------------------------------------------------- Alerts

    'Alert' => $obj([
        'id' => $ulid(), 'type' => $str(),
        'payload' => ['type' => 'object', 'additionalProperties' => true],
        'channels' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => ['pending', 'sent', 'failed']], 'description' => 'Per-channel delivery state.'],
        'status' => $enum(['pending', 'sent', 'failed']),
        'scheduled_at' => $dt(), 'sent_at' => $dt(), 'read_at' => $dt(),
    ], ['id', 'type', 'status']),

    'AlertRule' => $obj([
        'id' => $ulid(),
        'type' => $enum(['check_due', 'installment_due', 'budget_threshold', 'low_balance']),
        'config' => ['type' => 'object', 'additionalProperties' => true],
        'channels' => $arr($enum(['database', 'push', 'email', 'sms', 'telegram', 'whatsapp']), '`database` is always present — it is appended by the server, so an alert always has somewhere to land even if every external channel fails.'),
        'lead_days' => $int('How far ahead of the due date to fire.'),
        'is_active' => $bool(),
    ], ['id', 'type']),

    'AlertPreferences' => $obj([
        'channels' => $obj([
            'database' => $bool(), 'push' => $bool(), 'email' => $bool(),
            'sms' => $bool(), 'telegram' => $bool(), 'whatsapp' => $bool(),
        ], [], 'All six keys are always present.'),
        'quiet_hours_start' => ['type' => ['string', 'null'], 'description' => '`HH:MM`. Alerts inside the window are deferred, not dropped.'],
        'quiet_hours_end' => ['type' => ['string', 'null'], 'description' => '`HH:MM`.'],
        'timezone' => ['type' => ['string', 'null']],
    ], ['channels']),

    // ---------------------------------------------------------------- Billing

    'Plan' => $obj([
        'code' => $str(), 'name' => $str(),
        'price' => $ref('BillingMoney'),
        'interval' => $enum(['monthly', 'yearly']),
        'features' => ['type' => 'object', 'additionalProperties' => true],
    ], ['code', 'name', 'price']),

    'Subscription' => $obj([
        'id' => $ulid(), 'plan_code' => $str(),
        'effective_plan_code' => $str('What the workspace is actually entitled to right now, which differs from `plan_code` during a trial.'),
        'status' => $str(),
        'on_trial' => $bool(),
        'trial_ends_at' => $dt(), 'renews_at' => $dt(), 'cancelled_at' => $dt(),
    ], ['id', 'plan_code', 'status']),

    'SubscriptionInvoice' => $obj([
        'id' => $ulid(), 'number' => $str(), 'plan_code' => $str(),
        'amount' => $ref('BillingMoney'),
        'status' => $enum(['pending', 'paid', 'failed', 'refunded']),
        'period_start' => $dt(), 'period_end' => $dt(),
        'issued_at' => $dt(), 'paid_at' => $dt(),
    ], ['id', 'amount', 'status']),

    'Entitlements' => $obj([
        'plan' => $str(),
        'flags' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']],
        'limits' => ['type' => 'object', 'additionalProperties' => ['type' => ['integer', 'null']], 'description' => 'Null means unlimited.'],
    ], ['plan']),

    // ------------------------------------------------------------------- Core

    'Workspace' => $obj([
        'id' => $ulid(), 'name' => $str(),
        'type' => $enum(['personal', 'business', 'building', 'travel', 'family', 'store']),
        'base_currency' => $enum($currencies, 'Every report and every `base` amount in this workspace is stated in this currency.'),
        'locale' => $enum(['fa', 'en', 'tr', 'ar']),
        'calendar' => ['type' => ['string', 'null']],
        'icon' => ['type' => ['string', 'null']], 'color' => ['type' => ['string', 'null']],
        'role' => $enum(['owner', 'admin', 'accountant', 'member', 'viewer'], "The CALLER's role in this workspace."),
    ], ['id', 'name', 'type', 'base_currency']),

    'User' => $obj([
        'id' => $int(), 'name' => $str(), 'email' => ['type' => 'string', 'format' => 'email'],
        'locale' => $enum(['fa', 'en', 'tr', 'ar']),
    ], ['id', 'name', 'email']),

    'AuthSuccess' => $obj([
        'token' => $str('Sanctum plain-text token. Shown once — it is not retrievable later.'),
        'user' => $ref('User'),
        'workspace' => ['allOf' => [['$ref' => '#/components/schemas/Workspace']], 'description' => 'Register only: the workspace seeded for the new account.'],
        'workspaces' => $arr($ref('Workspace'), 'Login only: every workspace the user belongs to. Pick one and send its id as `X-Workspace-Id`.'),
    ], ['token', 'user']),

    'Member' => $obj([
        'id' => $ulid(),
        'role' => $enum(['owner', 'admin', 'accountant', 'member', 'viewer']),
        'joined_at' => $dt(),
        'user' => $ref('User'),
    ], ['id', 'role']),

    'Invitation' => $obj([
        'id' => $ulid(),
        'email' => ['type' => 'string', 'format' => 'email', 'description' => 'The accepting user\'s email must match this, or holding the link would be enough to walk into a stranger\'s books.'],
        'role' => $enum(['admin', 'accountant', 'member', 'viewer'], 'Never `owner` — a workspace has exactly one, and allowing a second would let an admin lock the owner out.'),
        'status' => $enum(['pending', 'accepted', 'revoked', 'expired']),
        'expires_at' => $dt(),
        'created_at' => $dt(),
        'token' => ['type' => ['string', 'null'], 'description' => 'Plain-text invitation token. Returned ONCE, from the create call only — the database stores a hash, so a leaked dump hands nobody a working link.'],
    ], ['id', 'email', 'role', 'status']),

    // --------------------------------------------------------------- Security

    'TwoFactorStatus' => $obj([
        'enabled' => $bool(),
        'pending_confirmation' => $bool('Secret issued but not yet confirmed with a code.'),
        'confirmed_at' => $dt(),
        'recovery_codes_remaining' => $int(),
    ], ['enabled']),

    // ------------------------------------------------------------------- Sync

    'SyncChange' => $obj([
        'entity' => $enum(['transaction', 'account', 'category', 'budget']),
        'id' => $str(),
        'op' => $enum(['create', 'update', 'delete']),
        'version' => $int(),
        'updated_at' => $str('`YYYY-MM-DDTHH:MM:SSZ`.'),
        'payload' => ['type' => ['object', 'null'], 'additionalProperties' => true, 'description' => 'The entity\'s synced fields plus `id`, `version`, `updated_at`, `deleted_at`. Money fields inside a payload are bare integers in minor units with a sibling currency field, matching the columns.'],
    ], ['entity', 'id', 'op', 'version'], 'One record in the pull feed.'),

    'PushVerdict' => $obj([
        'entity' => $str(), 'id' => $str(), 'op' => $str(),
        'status' => $enum(['applied', 'merged', 'conflict', 'rejected']),
        'server_version' => $int(),
        'reason' => ['type' => ['string', 'null'], 'enum' => ['entity_missing', 'deleted_on_server', 'delete_vs_edit', 'financial_conflict', 'server_newer', 'not_applicable', null], 'description' => 'Why the change was not simply applied.'],
        'server_payload' => ['type' => ['object', 'null'], 'additionalProperties' => true, 'description' => 'The server\'s version of the record. Present ONLY when `status` is `conflict`, because that is the only case where the client has to show a human both sides.'],
        'message' => ['type' => ['string', 'null'], 'description' => 'Present on `rejected`.'],
    ], ['entity', 'id', 'op', 'status'], "The server's answer for one pushed change. A disagreement about an amount, currency, account or date is NEVER auto-resolved — it comes back as `conflict` for a person to settle. Non-financial fields merge last-write-wins."),

    'Device' => $obj([
        'id' => $ulid(), 'type' => $str('Always the literal `device`.'),
        'platform' => $enum(['ios', 'android', 'web', 'desktop', 'unknown']),
        'name' => ['type' => ['string', 'null']],
        'has_push_token' => $bool('Whether a push token is stored. The token itself is never echoed.'),
        'last_seen_at' => $dt(), 'revoked_at' => $dt(), 'created_at' => $dt(),
    ], ['id', 'platform']),

    // ------------------------------------------------------------ Audit / Ops

    'AuditLogEntry' => $obj([
        'id' => $ulid(),
        'action' => $str('e.g. `auth.login`, `auth.login_failed`, `transaction.updated`.'),
        'subject_type' => ['type' => ['string', 'null']],
        'subject_id' => ['type' => ['string', 'null']],
        'before' => ['type' => ['object', 'null'], 'additionalProperties' => true, 'description' => 'Only the fields that actually changed. Credentials and tokens are redacted; amounts are kept verbatim, since recording them is the point.'],
        'after' => ['type' => ['object', 'null'], 'additionalProperties' => true],
        'ip' => ['type' => ['string', 'null']],
        'user_agent' => ['type' => ['string', 'null']],
        'created_at' => $dt(),
        'user' => ['oneOf' => [['type' => 'object', 'properties' => ['id' => $int(), 'name' => $str()]], ['type' => 'null']]],
    ], ['id', 'action'], 'Append-only: updating or deleting an entry throws. A trail you can quietly edit is not a trail.'),

    'DataExport' => $obj([
        'id' => $ulid(),
        'status' => $enum(['pending', 'running', 'ready', 'failed']),
        'format' => $enum(['zip']),
        'size' => ['type' => ['integer', 'null']],
        'error' => ['type' => ['string', 'null']],
        'requested_by' => ['type' => ['integer', 'null']],
        'expires_at' => $dt(),
        'download_url' => ['type' => ['string', 'null'], 'description' => 'Absolute URL, non-null only while the export is ready and unexpired.'],
        'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'status', 'format']),

    'Employee' => $obj([
        'id' => $ulid(),
        'employee_number' => ['type' => ['string', 'null']],
        'name' => $str(),
        'job_title' => ['type' => ['string', 'null']],
        'email' => ['type' => ['string', 'null']],
        'phone' => ['type' => ['string', 'null']],
        'country' => ['type' => ['string', 'null'], 'description' => 'ISO 3166-1 alpha-2. This is what selects the tax rule set, which is why tax is pluggable rather than written into the calculation.'],
        'status' => $enum(['active', 'on_leave', 'ended']),
        'has_ended' => $bool('An ended employee is never included in a new run.'),
        'started_on' => $date(), 'ended_on' => $date(),
        'has_national_id' => $bool('Whether one is stored. The identifier itself is encrypted at rest and is never sent to a client — docs/07-security.md, data minimisation.'),
        'compensation' => ['type' => ['object', 'null'], 'description' => 'The rate in force today, or on the end date for a leaver.', 'properties' => [
            'id' => $ulid(), 'amount' => $ref('Money'),
            'period' => $enum(['monthly', 'annual', 'weekly', 'daily']),
            'effective_from' => $date(),
        ]],
        'compensations' => $arr($obj([
            'id' => $ulid(), 'amount' => $ref('Money'),
            'period' => $enum(['monthly', 'annual', 'weekly', 'daily']),
            'effective_from' => $date(),
        ]), 'The full rate history, only when the relation was loaded.'),
        'notes' => ['type' => ['string', 'null']],
        'version' => $int(), 'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'name', 'status', 'started_on']),

    'PayrollRun' => $obj([
        'id' => $ulid(),
        'reference' => ['type' => ['string', 'null']],
        'status' => $enum(['draft', 'approved', 'paid'], 'Approving is what posts to the ledger. A draft posts nothing; a paid run refuses mutation.'),
        'period_start' => $date(), 'period_end' => $date(), 'pay_date' => $date(),
        'gross' => $ref('Money'),
        'deductions' => $ref('Money'),
        'contributions' => $ref('Money'),
        'net' => $ref('Money'),
        'employer_cost' => $ref('Money'),
        'account_id' => $ulid('The account the net pay is drawn from.'),
        'category_id' => ['type' => ['string', 'null']],
        'is_posted' => $bool('False for a draft.'),
        'net_transaction_id' => ['type' => ['string', 'null'], 'description' => 'Set once on approval. Approving twice does not post twice.'],
        'liability_transaction_id' => ['type' => ['string', 'null'], 'description' => 'Deductions and employer contributions owed to third parties.'],
        'approved_at' => $dt(), 'approved_by' => ['type' => ['integer', 'null']], 'paid_at' => $dt(),
        'payslip_count' => $int(),
        'payslips' => $arr($ref('Payslip'), 'Only when the relation was loaded.'),
        'notes' => ['type' => ['string', 'null']],
        'version' => $int(), 'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'status', 'period_start', 'period_end'], 'net = gross - deductions, exactly, in integer minor units.'),

    'Payslip' => $obj([
        'id' => $ulid(),
        'payroll_run_id' => $ulid(),
        'employee_id' => $ulid(),
        'gross' => $ref('Money'), 'deductions' => $ref('Money'),
        'contributions' => $ref('Money'), 'net' => $ref('Money'),
        'employer_cost' => $ref('Money'),
        'period_days' => $int(), 'worked_days' => $int(),
        'is_prorated' => $bool('True when the employee did not work the whole period, e.g. a starter or a leaver.'),
        'country' => ['type' => ['string', 'null']],
        'tax_rules_name' => ['type' => ['string', 'null'], 'description' => 'The rule set applied, recorded on the payslip so a later rule change cannot silently restate a past run.'],
        'employee' => ['type' => ['object', 'null'], 'properties' => ['id' => $ulid(), 'name' => $str(), 'job_title' => ['type' => ['string', 'null']]]],
        'lines' => $arr($obj([
            'id' => $ulid(),
            'kind' => $enum(['earning', 'deduction', 'contribution'], 'A contribution is paid by the employer and is not subtracted from net.'),
            'code' => ['type' => ['string', 'null']], 'label' => ['type' => ['string', 'null']],
            'amount' => $ref('Money'),
            'rate' => ['type' => ['number', 'null'], 'description' => 'Percentage, when the line came from a rate rather than a fixed amount.'],
            'sort_order' => $int(),
        ]), 'Only when the relation was loaded.'),
        'version' => $int(), 'created_at' => $dt(),
    ], ['id', 'payroll_run_id', 'employee_id']),

    'TaxRuleSet' => $obj([
        'id' => $ulid(),
        'country' => $str('ISO 3166-1 alpha-2.'),
        'name' => ['type' => ['string', 'null']],
        'currency' => ['type' => ['string', 'null']],
        'effective_from' => $date(),
        'is_active' => $bool(),
        'rules' => $obj([
            'tax_base' => $enum(['gross', 'gross_less_employee_contributions']),
            'brackets' => $arr($obj([
                'up_to' => ['type' => ['integer', 'null'], 'description' => 'Upper bound in INTEGER minor units. Null means the top bracket.'],
                'rate' => $num('Percentage.'),
            ]), 'Progressive, applied in order.'),
            'employee_contributions' => $arr($obj(['code' => $str(), 'label' => ['type' => ['string', 'null']], 'rate' => $num('Percentage.'), 'cap' => ['type' => ['integer', 'null'], 'description' => 'INTEGER minor units.']])),
            'employer_contributions' => $arr($obj(['code' => $str(), 'label' => ['type' => ['string', 'null']], 'rate' => $num('Percentage.'), 'cap' => ['type' => ['integer', 'null'], 'description' => 'INTEGER minor units.']])),
            'fixed_deductions' => $arr($obj(['code' => $str(), 'label' => ['type' => ['string', 'null']], 'amount' => $int('INTEGER minor units.')])),
        ]),
        'version' => $int(), 'created_at' => $dt(), 'updated_at' => $dt(),
    ], ['id', 'country', 'rules'], 'Per workspace and country. Nothing here hard-codes one country\'s law into the calculation path.'),
];
