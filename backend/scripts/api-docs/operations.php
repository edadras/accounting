<?php

declare(strict_types=1);

/**
 * One entry per live route, keyed `METHOD /path`.
 *
 * build-api-docs.php refuses to emit anything unless this table and the router
 * agree exactly, in both directions. Adding a route without adding it here
 * fails CI; deleting a route and leaving it here fails CI too.
 *
 * Everything the router already knows — path, method, whether Sanctum and
 * ResolveWorkspace are in the stack — is deliberately NOT repeated here. Only
 * payload facts live in this file.
 */

$R = fn (string $name): array => ['$ref' => '#/components/schemas/'.$name];

/** `{ "data": X }` */
$data = fn (array $schema): array => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $schema]];
/** `{ "data": [X] }` */
$list = fn (array $schema): array => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => $schema]]];
/** `{ "data": [X], "meta": {page, per_page, total} }` */
$paged = fn (array $schema): array => ['type' => 'object', 'required' => ['data', 'meta'], 'properties' => [
    'data' => ['type' => 'array', 'items' => $schema],
    'meta' => ['$ref' => '#/components/schemas/PageMeta'],
]];
/** `{ "data": [X], "meta": {...custom} }` */
$listMeta = fn (array $schema, array $meta): array => ['type' => 'object', 'required' => ['data'], 'properties' => [
    'data' => ['type' => 'array', 'items' => $schema],
    'meta' => ['type' => 'object', 'properties' => $meta],
]];
$dataMeta = fn (array $schema, array $meta): array => ['type' => 'object', 'required' => ['data'], 'properties' => [
    'data' => $schema,
    'meta' => ['type' => 'object', 'properties' => $meta],
]];

$res = fn (string $description, ?array $schema = null): array => $schema === null
    ? ['description' => $description]
    : ['description' => $description, 'schema' => $schema];

$binary = fn (string $description, string $mime): array => [
    'description' => $description,
    'type' => $mime,
    'schema' => ['type' => 'string', 'format' => 'binary'],
    'headers' => [
        'Content-Disposition' => ['schema' => ['type' => 'string'], 'description' => '`attachment; filename="..."`'],
    ],
];

$body = fn (array $properties, array $required = []): array => array_filter([
    'type' => 'object',
    'required' => $required === [] ? null : $required,
    'properties' => $properties,
], fn ($v) => $v !== null);

$str = fn (string $d = '', array $x = []): array => array_merge(['type' => 'string'], $d === '' ? [] : ['description' => $d], $x);
$int = fn (string $d = '', array $x = []): array => array_merge(['type' => 'integer'], $d === '' ? [] : ['description' => $d], $x);
$num = fn (string $d = '', array $x = []): array => array_merge(['type' => 'number'], $d === '' ? [] : ['description' => $d], $x);
$bool = fn (string $d = ''): array => array_filter(['type' => 'boolean', 'description' => $d === '' ? null : $d]);
$enum = fn (array $v, string $d = ''): array => array_filter(['type' => 'string', 'enum' => $v, 'description' => $d === '' ? null : $d]);
$date = fn (string $d = ''): array => array_filter(['type' => 'string', 'format' => 'date', 'description' => $d === '' ? null : $d]);
$dt = fn (string $d = ''): array => array_filter(['type' => 'string', 'format' => 'date-time', 'description' => $d === '' ? null : $d]);
$ulidIn = fn (string $d): array => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26, 'description' => $d];

/** A client-supplied id. This is what lets a record created offline keep its identity through sync. */
$clientId = ['type' => 'string', 'minLength' => 26, 'maxLength' => 26, 'description' => 'Optional client-generated ULID. Supplying it is what lets a record created offline keep the same id after it syncs.'];

/** The recurring money pair on write bodies. */
$amountIn = fn (string $extra = ''): array => ['type' => 'integer', 'minimum' => 1, 'description' => trim('INTEGER in the currency\'s minor units. 35000 with currency TRY is ₺350.00, not ₺35,000. '.$extra)];

$currencies = ['IRR', 'IRT', 'TRY', 'USD', 'EUR', 'AED', 'BTC', 'ETH', 'USDT', 'XAU'];
$currencyIn = fn (string $d = 'Currency of `amount`.') => ['type' => 'string', 'enum' => $currencies, 'description' => $d];

$q = fn (string $description, array $schema = ['type' => 'string'], string $example = ''): array => [
    'description' => $description,
    'schema' => $schema,
    'example' => $example,
];

$perPage = $q('Page size. Default 50, maximum 200.', ['type' => 'integer', 'maximum' => 200], '50');
$page = $q('1-based page number.', ['type' => 'integer'], '1');

/** The AI/Capture confirm body — identical in both modules. */
$confirmBody = $body([
    'account_id' => $ulidIn('Account to post to, overriding the draft\'s suggestion.'),
    'category_id' => ['type' => ['string', 'null'], 'description' => 'Category, overriding the suggestion.'],
    'amount' => $amountIn('Overrides the extracted amount.'),
    'currency' => $str('Overrides the extracted currency.'),
    'occurred_at' => $dt(),
    'description' => ['type' => ['string', 'null']],
    'type' => $enum(['income', 'expense']),
]);

$reportFilters = [
    'from' => $q('Start of the range, inclusive. Defaults to the start of the current month.', ['type' => 'string', 'format' => 'date'], '2026-07-01'),
    'to' => $q('End of the range, inclusive to 23:59:59. Defaults to today.', ['type' => 'string', 'format' => 'date'], '2026-07-31'),
    'bucket' => $q('Period grouping. Default `month`.', ['type' => 'string', 'enum' => ['day', 'week', 'month', 'year']], 'month'),
    'limit' => $q('Rows for the `top-*` reports. 1-100, default 10.', ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], '10'),
    'depth' => $q('Category depth to aggregate at. 1-10, default 1.', ['type' => 'integer', 'minimum' => 1, 'maximum' => 10], '1'),
    'flow' => $q('Which side to rank. Default `expense`.', ['type' => 'string', 'enum' => ['income', 'expense']], 'expense'),
];

return [

    // =========================================================== Ledger

    'GET /api/v1/accounts' => [
        'id' => 'listAccounts', 'tag' => 'Ledger',
        'summary' => 'List accounts',
        'query' => ['include_archived' => $q('Include archived accounts.', ['type' => 'boolean'], '1')],
        'responses' => [200 => $res('Accounts, with the workspace total.', $listMeta($R('Account'), [
            'total' => ['allOf' => [$R('MoneyBrief')], 'description' => 'Sum of every listed balance, converted to the workspace base currency.'],
        ]))],
    ],
    'POST /api/v1/accounts' => [
        'id' => 'createAccount', 'tag' => 'Ledger',
        'summary' => 'Create an account',
        'body' => $body([
            'id' => $clientId,
            'name' => $str('', ['maxLength' => 120]),
            'type' => $enum(['cash', 'bank', 'card', 'wallet', 'fund', 'petty_cash', 'crypto', 'gold', 'fx']),
            'currency' => $currencyIn('Currency this account holds. Cannot be changed later.'),
            'opening_balance' => $int('INTEGER in minor units. May be negative for a liability account.'),
            'iban' => $str('', ['maxLength' => 34]),
            'card_last4' => $str('', ['minLength' => 4, 'maxLength' => 4]),
            'icon' => $str('', ['maxLength' => 64]),
            'color' => $str('', ['maxLength' => 16]),
        ], ['name', 'type', 'currency']),
        'responses' => [
            201 => $res('Created.', $data($R('Account'))),
            403 => ['$ref' => '#/components/responses/Forbidden'],
        ],
    ],
    'GET /api/v1/accounts/{id}' => [
        'id' => 'getAccount', 'tag' => 'Ledger',
        'summary' => 'Get an account',
        'responses' => [200 => $res('The account.', $data($R('Account'))), 404 => ['$ref' => '#/components/responses/NotFound']],
    ],
    'GET /api/v1/categories' => [
        'id' => 'listCategories', 'tag' => 'Ledger',
        'summary' => 'List categories',
        'query' => [
            'type' => $q('Restrict to categories usable for this flow. Categories of type `both` always match.', ['type' => 'string', 'enum' => ['income', 'expense']], 'expense'),
            'tree' => $q('Return roots only, each with a nested `children` array, instead of a flat list.', ['type' => 'boolean'], '1'),
        ],
        'responses' => [200 => $res('Categories, flat or nested depending on `tree`.', $list($R('Category')))],
    ],
    'POST /api/v1/categories' => [
        'id' => 'createCategory', 'tag' => 'Ledger',
        'summary' => 'Create a category',
        'body' => $body([
            'id' => $clientId,
            'parent_id' => ['type' => ['string', 'null'], 'description' => 'Parent category. Depth is unlimited.'],
            'name' => $str('', ['maxLength' => 120]),
            'type' => $enum(['income', 'expense', 'both']),
            'icon' => $str('', ['maxLength' => 64]),
            'color' => $str('', ['maxLength' => 16]),
        ], ['name', 'type']),
        'responses' => [201 => $res('Created.', $data($R('Category'))), 403 => ['$ref' => '#/components/responses/Forbidden']],
    ],
    'GET /api/v1/transactions' => [
        'id' => 'listTransactions', 'tag' => 'Ledger',
        'summary' => 'List transactions',
        'description' => 'Filtering by `category_id` includes the whole subtree — filtering by "Food" '
            .'returns Restaurants and Groceries too, otherwise every parent category would read as empty.',
        'query' => [
            'type' => $q('', ['type' => 'string', 'enum' => ['income', 'expense', 'transfer']], 'expense'),
            'account_id' => $q('', ['type' => 'string'], ''),
            'category_id' => $q('Matches the category and everything beneath it.', ['type' => 'string'], ''),
            'from' => $q('`occurred_at >=`.', ['type' => 'string', 'format' => 'date'], '2026-07-01'),
            'to' => $q('`occurred_at <=`.', ['type' => 'string', 'format' => 'date'], '2026-07-31'),
            'q' => $q('Substring match over description, payee and notes.', ['type' => 'string'], ''),
            'per_page' => $perPage, 'page' => $page,
        ],
        'responses' => [200 => $res('A page of transactions. `entries` are not included here — request one transaction to see its double-entry rows.', $paged($R('Transaction')))],
    ],
    'POST /api/v1/transactions' => [
        'id' => 'createTransaction', 'tag' => 'Ledger',
        'summary' => 'Record a transaction',
        'description' => "Posts balanced double-entry rows inside one database transaction.\n\n"
            ."`amount` is always POSITIVE — direction is carried by `type`, not by the sign. A "
            ."negative amount is refused (`negative_amount`), as is zero (`zero_amount`).\n\n"
            .'This endpoint honours `Idempotency-Key`; the header takes precedence over the '
            .'`idempotency_key` body field, because the header is what a retry layer actually sets.',
        'body' => $body([
            'id' => $clientId,
            'type' => $enum(['income', 'expense', 'transfer']),
            'account_id' => $ulidIn('Source account. Must be in this workspace.'),
            'counter_account_id' => ['type' => ['string', 'null'], 'description' => 'Destination account. Required for a transfer, and must differ from `account_id`.'],
            'category_id' => ['type' => ['string', 'null']],
            'amount' => $amountIn('Always positive; `type` carries the direction.'),
            'currency' => $currencyIn('Must match the account currency, or the request is refused with `currency_mismatch` rather than silently converted.'),
            'fx_rate' => $num('Rate to the workspace base currency, in major units. Required when the currencies differ, because the ledger will not guess a rate.', ['exclusiveMinimum' => 0]),
            'occurred_at' => $dt('When the money moved. Defaults to now.'),
            'description' => $str('', ['maxLength' => 255]),
            'notes' => $str('', ['maxLength' => 5000]),
            'payee' => $str('', ['maxLength' => 255]),
            'reference' => $str('', ['maxLength' => 255]),
            'tags' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 40]],
            'source' => $enum(['manual', 'voice', 'ocr', 'sms', 'email', 'qr', 'import', 'recurring', 'api']),
            'source_meta' => ['type' => 'object', 'additionalProperties' => true],
            'latitude' => $num('', ['minimum' => -90, 'maximum' => 90]),
            'longitude' => $num('', ['minimum' => -180, 'maximum' => 180]),
            'idempotency_key' => $str('Fallback for clients that cannot set the header.', ['maxLength' => 64]),
        ], ['type', 'account_id', 'amount', 'currency']),
        'responses' => [
            201 => $res('Recorded.', $data($R('Transaction'))),
            403 => ['$ref' => '#/components/responses/Forbidden'],
            404 => ['$ref' => '#/components/responses/NotFound'],
        ],
    ],
    'GET /api/v1/transactions/{id}' => [
        'id' => 'getTransaction', 'tag' => 'Ledger',
        'summary' => 'Get a transaction',
        'responses' => [200 => $res('The transaction, including its double-entry `entries`.', $data($R('Transaction'))), 404 => ['$ref' => '#/components/responses/NotFound']],
    ],
    'DELETE /api/v1/transactions/{id}' => [
        'id' => 'deleteTransaction', 'tag' => 'Ledger',
        'summary' => 'Delete a transaction',
        'description' => 'Removes the entry rows and recalculates both affected account balances.',
        'responses' => [204 => $res('Deleted.'), 404 => ['$ref' => '#/components/responses/NotFound']],
    ],

    // =========================================================== Budget

    'GET /api/v1/budgets' => [
        'id' => 'listBudgets', 'tag' => 'Budget',
        'summary' => 'List budgets',
        'query' => [
            'scope' => $q('', ['type' => 'string', 'enum' => ['overall', 'category', 'project', 'trip', 'building', 'member']], 'category'),
            'scope_id' => $q('', ['type' => 'string'], ''),
            'period' => $q('', ['type' => 'string', 'enum' => ['monthly', 'yearly', 'custom']], 'monthly'),
        ],
        'responses' => [200 => $res('Budgets.', $list($R('Budget')))],
    ],
    'POST /api/v1/budgets' => [
        'id' => 'createBudget', 'tag' => 'Budget',
        'summary' => 'Create a budget',
        'body' => $body([
            'id' => $clientId,
            'name' => $str('', ['maxLength' => 120]),
            'scope' => $enum(['overall', 'category', 'project', 'trip', 'building', 'member']),
            'scope_id' => ['type' => ['string', 'null'], 'description' => 'Required unless `scope` is `overall`, in which case the server forces it to null.'],
            'period' => $enum(['monthly', 'yearly', 'custom']),
            'starts_at' => $dt(),
            'ends_at' => $dt('Required when `period` is `custom`.'),
            'amount' => $amountIn(),
            'currency' => $currencyIn(),
            'rollover' => $bool('Carry an unspent remainder into the next period.'),
            'alert_thresholds' => ['type' => 'array', 'maxItems' => 10, 'items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000], 'description' => 'Percentages, e.g. `[80, 100]`.'],
        ], ['name', 'scope', 'period', 'starts_at', 'amount', 'currency']),
        'responses' => [201 => $res('Created.', $data($R('Budget'))), 403 => ['$ref' => '#/components/responses/Forbidden']],
    ],
    'GET /api/v1/budgets/status' => [
        'id' => 'getBudgetStatus', 'tag' => 'Budget',
        'summary' => 'Budget consumption',
        'description' => 'Live spend against every budget for the period containing `at`. A category '
            .'budget counts its whole subtree, and transfers never count as spending.',
        'query' => ['at' => $q('Point in time to evaluate. Defaults to now.', ['type' => 'string', 'format' => 'date'], '2026-07-25')],
        'responses' => [200 => $res('Status per budget.', $list($R('BudgetStatus')))],
    ],
    'GET /api/v1/budgets/{id}' => [
        'id' => 'getBudget', 'tag' => 'Budget',
        'summary' => 'Get a budget',
        'responses' => [200 => $res('The budget.', $data($R('Budget'))), 404 => ['$ref' => '#/components/responses/NotFound']],
    ],
    'DELETE /api/v1/budgets/{id}' => [
        'id' => 'deleteBudget', 'tag' => 'Budget',
        'summary' => 'Delete a budget',
        'responses' => [204 => $res('Deleted.'), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']],
    ],

    // =========================================================== Reports

    'GET /api/v1/reports' => [
        'id' => 'listReportTypes', 'tag' => 'Reports',
        'summary' => 'Report catalogue',
        'responses' => [200 => $res('What can be asked for.', $data(['type' => 'object', 'properties' => [
            'types' => ['type' => 'array', 'items' => ['type' => 'string']],
            'buckets' => ['type' => 'array', 'items' => ['type' => 'string']],
            'export_formats' => ['type' => 'array', 'items' => ['type' => 'string']],
            'export_locales' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]]))],
    ],
    'GET /api/v1/reports/{type}' => [
        'id' => 'getReport', 'tag' => 'Reports',
        'summary' => 'Run a report',
        'path' => ['type' => ['type' => 'string', 'enum' => ['cash-flow', 'net-worth', 'expense-trend', 'income-trend', 'top-categories', 'top-merchants', 'top-accounts', 'category-breakdown']]],
        'pathDesc' => ['type' => 'Report type. `GET /reports` lists the valid values.'],
        'query' => $reportFilters,
        'responses' => [
            200 => $res('The report. Shape depends on `type` — see the `Report` schema.', $data($R('Report'))),
            404 => $res('Unknown report type. The body lists the valid ones in `error.allowed`.', ['allOf' => [$R('Error')]]),
        ],
    ],
    'POST /api/v1/reports/{type}/export' => [
        'id' => 'exportReport', 'tag' => 'Reports',
        'summary' => 'Export a report',
        'description' => "Answers one of two ways, and a client must handle both:\n\n"
            ."- **202** with a `ReportExport` record, when the export was queued — either because "
            ."`queued: true` was sent or because the result is larger than the inline row limit "
            ."for the format. Poll `GET /reports/exports/{id}` and use its `download_url`.\n"
            ."- **200** with the file bytes, when it was small enough to build inline.\n\n"
            .'Pass `queued: true` if you want an id you can poll and a link you can hand to a user; '
            .'an inline export gives you neither.',
        'path' => ['type' => ['type' => 'string']],
        'body' => $body([
            'format' => $enum(['csv', 'xlsx', 'pdf'], 'XLSX writes amounts as numbers with a currency format, so a column can be summed. CSV writes plain decimals with no thousands separator, which would make the file unparseable in half the world\'s locales.'),
            'locale' => $enum(['fa', 'en', 'tr', 'ar'], 'Language of the FILE, chosen separately from the app language. Default `fa`.'),
            'queued' => $bool('Force the queued path.'),
            'from' => $date(), 'to' => $date(),
            'bucket' => $enum(['day', 'week', 'month', 'year']),
            'limit' => $int('', ['minimum' => 1, 'maximum' => 100]),
            'depth' => $int('', ['minimum' => 1, 'maximum' => 10]),
            'flow' => $enum(['income', 'expense']),
        ], ['format']),
        'responses' => [
            200 => $binary('The file itself, when built inline. `X-Report-Export-Id` carries the export id.', 'application/octet-stream'),
            202 => $res('Queued. Poll the returned record.', $data($R('ReportExport'))),
            404 => $res('Unknown report type.', $R('Error')),
        ],
    ],
    'GET /api/v1/reports/exports' => [
        'id' => 'listReportExports', 'tag' => 'Reports',
        'summary' => 'List report exports',
        'query' => ['limit' => $q('Default 50, maximum 200.', ['type' => 'integer', 'maximum' => 200], '50')],
        'responses' => [200 => $res('Exports, with the catalogue of valid formats, locales and types.', $listMeta($R('ReportExport'), [
            'formats' => ['type' => 'array', 'items' => ['type' => 'string']],
            'locales' => ['type' => 'array', 'items' => ['type' => 'string']],
            'types' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]))],
    ],
    'GET /api/v1/reports/exports/{id}' => [
        'id' => 'getReportExport', 'tag' => 'Reports',
        'summary' => 'Get a report export',
        'responses' => [200 => $res('The export. `download_url` is null until `status` is `ready`.', $data($R('ReportExport'))), 404 => ['$ref' => '#/components/responses/NotFound']],
    ],
    'GET /api/v1/reports/exports/{id}/download' => [
        'id' => 'downloadReportExport', 'tag' => 'Reports',
        'summary' => 'Download a report export',
        'responses' => [
            200 => $binary('The generated file.', 'application/octet-stream'),
            404 => $res('Not ready yet, or the stored file is gone.'),
        ],
    ],

    // =========================================================== Search

    'GET /api/v1/search' => [
        'id' => 'search', 'tag' => 'Search',
        'summary' => 'Search',
        'description' => 'Lexical search with Persian/Arabic normalisation applied at write time, so '
            .'a query typed with Arabic ی/ک finds text stored with the Persian forms and vice versa.',
        'query' => [
            'q' => $q('Query text. Required.', ['type' => 'string', 'maxLength' => 200], 'taxi'),
            'types' => $q('Comma-separated subset of `transactions,documents,categories`. Anything unrecognised searches all three.', ['type' => 'string'], 'transactions'),
            'limit' => $q('Results PER TYPE. 1-100, default 20.', ['type' => 'integer', 'maximum' => 100], '20'),
        ],
        'responses' => [200 => $res('Results grouped by type. Note `data` is an OBJECT keyed by type, not an array.', ['type' => 'object', 'properties' => [
            'data' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]]],
            'meta' => ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string'],
                'normalized_query' => ['type' => 'string', 'description' => 'What was actually matched against, after folding.'],
                'types' => ['type' => 'array', 'items' => ['type' => 'string']],
                'limit' => ['type' => 'integer'], 'total' => ['type' => 'integer'],
            ]],
        ]])],
    ],
    'GET /api/v1/search/semantic' => [
        'id' => 'semanticSearch', 'tag' => 'Search',
        'summary' => 'Semantic search',
        'description' => 'Meaning-based search over a local concept lexicon, so "everything to do '
            .'with the car" finds fuel, garage, insurance and fines across several categories '
            .'without a network call. Results are a single ranked list, not grouped.',
        'query' => [
            'q' => $q('Query text. Required.', ['type' => 'string', 'maxLength' => 200], 'everything about the car'),
            'types' => $q('Comma-separated subset of `transactions,documents,categories`.', ['type' => 'string'], 'transactions'),
            'limit' => $q('1-100, default 20.', ['type' => 'integer', 'maximum' => 100], '20'),
        ],
        'responses' => [200 => $res('Ranked hits.', ['type' => 'object', 'properties' => [
            'data' => ['type' => 'array', 'items' => $R('SearchHit')],
            'meta' => ['type' => 'object', 'properties' => [
                'query' => ['type' => 'string'], 'normalized_query' => ['type' => 'string'],
                'filter' => ['type' => 'object', 'additionalProperties' => true],
                'summary' => ['type' => 'object', 'description' => '`{transaction_count, total, currency}` — `total` here is a BARE INTEGER in minor units, not a `Money` object.', 'additionalProperties' => true],
                'embedding_model' => ['type' => ['string', 'null']],
                'limit' => ['type' => 'integer'], 'scanned' => ['type' => 'integer'], 'total' => ['type' => 'integer'],
            ]],
        ]])],
    ],

    // =========================================================== MarketData

    'GET /api/v1/market/rates' => [
        'id' => 'getMarketRates', 'tag' => 'MarketData',
        'summary' => 'Exchange rate',
        'description' => 'Latest stored rate plus recent history. A rate that is zero, negative, '
            .'non-numeric or more than ten times the previous one is rejected on ingest rather than '
            .'stored, because a bad rate applied silently re-values every multi-currency balance in '
            .'the product. If nothing is stored, a resolver fallback answers with `source: "fallback"`.',
        'query' => [
            'base' => $q('Defaults to the configured base.', ['type' => 'string', 'enum' => $currencies], 'USD'),
            'quote' => $q('Defaults to the workspace base currency.', ['type' => 'string', 'enum' => $currencies], 'TRY'),
        ],
        'responses' => [200 => $res('The rate and its history.', $dataMeta(['type' => 'object', 'properties' => [
            'base' => ['type' => 'string'], 'quote' => ['type' => 'string'],
            'rate' => ['type' => ['string', 'null'], 'description' => 'Decimal STRING, not a number — the precision is preserved rather than being handed to a float.'],
            'source' => ['type' => ['string', 'null']],
            'rated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'history' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'rate' => ['type' => 'string'], 'source' => ['type' => 'string'], 'rated_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ]]],
        ]], ['request_id' => ['type' => ['string', 'null']]]))],
    ],
    'GET /api/v1/market/prices/{symbol}' => [
        'id' => 'getMarketPrice', 'tag' => 'MarketData',
        'summary' => 'Instrument price',
        'path' => ['symbol' => ['type' => 'string']],
        'pathDesc' => ['symbol' => 'Instrument symbol. Upper-cased server-side.'],
        'responses' => [
            200 => $res('Latest price, history, and any positions held in this workspace.', $dataMeta(['type' => 'object', 'properties' => [
                'symbol' => ['type' => 'string'], 'kind' => ['type' => ['string', 'null']],
                'price' => ['type' => ['string', 'null'], 'description' => 'Decimal STRING.'],
                'currency' => ['type' => ['string', 'null']],
                'source' => ['type' => ['string', 'null']],
                'captured_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'history' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'positions' => ['type' => 'array', 'items' => $R('Investment')],
            ]], ['request_id' => ['type' => ['string', 'null']]])),
            404 => $res('No price history and no position for that symbol (`not_found`).', $R('Error')),
            503 => ['$ref' => '#/components/responses/ServiceUnavailable'],
        ],
    ],

    // =========================================================== Banking

    'GET /api/v1/banks' => ['id' => 'listBanks', 'tag' => 'Banking', 'summary' => 'List banks',
        'responses' => [200 => $res('Banks.', $list($R('Bank')))]],
    'POST /api/v1/banks' => ['id' => 'createBank', 'tag' => 'Banking', 'summary' => 'Create a bank',
        'body' => $body([
            'id' => $clientId, 'name' => $str('', ['maxLength' => 160]),
            'branch' => $str('', ['maxLength' => 160]), 'swift' => $str('', ['maxLength' => 16]),
            'country' => $str('Two-letter country code.', ['minLength' => 2, 'maxLength' => 2]),
            'logo' => $str('', ['maxLength' => 255]),
        ], ['name']),
        'responses' => [201 => $res('Created.', $data($R('Bank'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/banks/{id}' => ['id' => 'getBank', 'tag' => 'Banking', 'summary' => 'Get a bank',
        'responses' => [200 => $res('The bank.', $data($R('Bank'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'PATCH /api/v1/banks/{id}' => ['id' => 'updateBank', 'tag' => 'Banking', 'summary' => 'Update a bank',
        'body' => $body([
            'name' => $str('', ['maxLength' => 160]), 'branch' => $str('', ['maxLength' => 160]),
            'swift' => $str('', ['maxLength' => 16]), 'country' => $str('', ['minLength' => 2, 'maxLength' => 2]),
            'logo' => $str('', ['maxLength' => 255]),
        ]),
        'responses' => [200 => $res('Updated.', $data($R('Bank'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'DELETE /api/v1/banks/{id}' => ['id' => 'deleteBank', 'tag' => 'Banking', 'summary' => 'Delete a bank',
        'responses' => [204 => $res('Deleted.'), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],

    'GET /api/v1/checks' => ['id' => 'listChecks', 'tag' => 'Banking', 'summary' => 'List cheques',
        'query' => [
            'status' => $q('', ['type' => 'string', 'enum' => ['draft', 'issued', 'in_progress', 'cleared', 'bounced', 'void']], 'issued'),
            'direction' => $q('', ['type' => 'string', 'enum' => ['received', 'issued', 'guarantee']], 'issued'),
            'due_before' => $q('`due_date <=`. Useful for "what falls due this month".', ['type' => 'string', 'format' => 'date'], '2026-08-31'),
        ],
        'responses' => [200 => $res('Cheques.', $list($R('Check')))]],
    'POST /api/v1/checks' => ['id' => 'createCheck', 'tag' => 'Banking', 'summary' => 'Create a cheque',
        'body' => $body([
            'id' => $clientId, 'account_id' => $ulidIn('Account the cheque is drawn on or paid into.'),
            'direction' => $enum(['received', 'issued', 'guarantee']),
            'check_number' => $str('', ['maxLength' => 64]),
            'amount' => $amountIn(), 'currency' => $currencyIn(),
            'due_date' => $date(),
            'status' => $enum(['draft', 'issued', 'in_progress', 'cleared', 'bounced', 'void']),
            'party_name' => $str('', ['maxLength' => 160]), 'notes' => $str(),
        ], ['account_id', 'direction', 'check_number', 'amount', 'currency', 'due_date']),
        'responses' => [201 => $res('Created.', $data($R('Check'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/checks/{id}' => ['id' => 'getCheck', 'tag' => 'Banking', 'summary' => 'Get a cheque',
        'responses' => [200 => $res('The cheque.', $data($R('Check'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'PATCH /api/v1/checks/{id}' => ['id' => 'updateCheck', 'tag' => 'Banking', 'summary' => 'Update a cheque',
        'body' => $body([
            'check_number' => $str('', ['maxLength' => 64]), 'due_date' => $date(),
            'status' => $enum(['draft', 'issued', 'in_progress', 'cleared', 'bounced', 'void']),
            'party_name' => $str('', ['maxLength' => 160]), 'notes' => $str(),
        ]),
        'responses' => [200 => $res('Updated.', $data($R('Check'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/checks/{id}/clear' => ['id' => 'clearCheck', 'tag' => 'Banking', 'summary' => 'Clear a cheque',
        'description' => 'Marks the cheque cleared and writes the matching ledger transaction. A cheque '
            .'that is not in a clearable state is refused with `check_not_clearable`, which carries the '
            .'current status in `details`.',
        'body' => $body(['cleared_at' => $dt('When it cleared. Defaults to now.')]),
        'bodyRequired' => false,
        'responses' => [200 => $res('Cleared.', $data($R('Check'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'DELETE /api/v1/checks/{id}' => ['id' => 'deleteCheck', 'tag' => 'Banking', 'summary' => 'Delete a cheque',
        'responses' => [204 => $res('Deleted.'), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],

    'GET /api/v1/loans' => ['id' => 'listLoans', 'tag' => 'Banking', 'summary' => 'List loans',
        'query' => ['status' => $q('', ['type' => 'string', 'enum' => ['active', 'closed', 'defaulted']], 'active')],
        'responses' => [200 => $res('Loans, without their schedules.', $list($R('Loan')))]],
    'POST /api/v1/loans' => ['id' => 'createLoan', 'tag' => 'Banking', 'summary' => 'Create a loan',
        'description' => 'Generates the full instalment schedule on creation. The principal parts across '
            .'the whole schedule sum to the principal exactly, and an annuity closes at zero.',
        'body' => $body([
            'id' => $clientId,
            'bank_id' => ['type' => ['string', 'null']],
            'account_id' => $ulidIn('Account the instalments are paid from.'),
            'title' => $str('', ['maxLength' => 160]),
            'principal' => $amountIn('The amount borrowed.'),
            'currency' => $currencyIn('Currency of `principal`.'),
            'interest_rate' => $num('Annual percentage.', ['minimum' => 0, 'maximum' => 1000]),
            'interest_type' => $enum(['simple', 'compound']),
            'installments_count' => $int('', ['minimum' => 1, 'maximum' => 600]),
            'start_date' => $date(),
            'penalty_rate' => $num('Late-payment percentage.', ['minimum' => 0, 'maximum' => 1000]),
        ], ['account_id', 'principal', 'currency', 'interest_rate', 'interest_type', 'installments_count', 'start_date']),
        'responses' => [201 => $res('Created, with the generated schedule in `installments`.', $data($R('Loan'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/loans/{id}' => ['id' => 'getLoan', 'tag' => 'Banking', 'summary' => 'Get a loan',
        'responses' => [200 => $res('The loan with its schedule.', $data($R('Loan'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/loans/{id}/schedule' => ['id' => 'getLoanSchedule', 'tag' => 'Banking', 'summary' => 'Get a loan schedule',
        'query' => ['regenerate' => $q('Rebuild the schedule. Requires a writing role.', ['type' => 'boolean'], '1')],
        'responses' => [200 => $res('The instalments and the totals they sum to.', $listMeta($R('LoanInstallment'), [
            'principal' => $R('Money'), 'total_principal' => $R('Money'),
            'total_interest' => $R('Money'), 'outstanding_balance' => $R('Money'),
        ]))]],
    'POST /api/v1/loans/{id}/installments/{number}/pay' => [
        'id' => 'payLoanInstallment', 'tag' => 'Banking',
        'summary' => 'Pay a loan instalment',
        'description' => 'Paying more than the instalment\'s remaining balance is refused with '
            .'`installment_overpayment` rather than absorbed. Note this endpoint reads the '
            .'`idempotency_key` BODY field only — it does not read the `Idempotency-Key` header.',
        'path' => ['id' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26], 'number' => ['type' => 'integer']],
        'pathDesc' => ['id' => 'Loan ULID.', 'number' => '1-based instalment number within the schedule.'],
        'body' => $body([
            'amount' => $amountIn('May be a partial payment.'),
            'paid_at' => $dt(),
            'penalty' => $int('Late-payment penalty, INTEGER in minor units.', ['minimum' => 0]),
            'notes' => $str(),
            'idempotency_key' => $str('Retry-safety key. This endpoint reads the body field, not the header.', ['maxLength' => 64]),
        ], ['amount']),
        'responses' => [
            200 => $res('The updated instalment, with the loan in `meta`.', $dataMeta($R('LoanInstallment'), ['loan' => $R('Loan')])),
            403 => ['$ref' => '#/components/responses/Forbidden'],
            404 => ['$ref' => '#/components/responses/NotFound'],
        ],
    ],

    // =========================================================== Investment

    'GET /api/v1/investments' => ['id' => 'listInvestments', 'tag' => 'Investment', 'summary' => 'List investments',
        'query' => ['kind' => $q('', ['type' => 'string', 'enum' => ['gold', 'fx', 'stock', 'etf', 'crypto', 'real_estate', 'vehicle', 'startup']], 'gold')],
        'responses' => [200 => $res('Positions, with portfolio totals in the workspace base currency.', $listMeta($R('Investment'), [
            'current_value' => $R('MoneyBrief'), 'total_profit' => $R('MoneyBrief'),
        ]))]],
    'POST /api/v1/investments' => ['id' => 'createInvestment', 'tag' => 'Investment', 'summary' => 'Create an investment',
        'body' => $body([
            'id' => $clientId, 'name' => $str('', ['maxLength' => 120]),
            'kind' => $enum(['gold', 'fx', 'stock', 'etf', 'crypto', 'real_estate', 'vehicle', 'startup']),
            'symbol' => $str('', ['maxLength' => 32]),
            'currency' => $currencyIn('Currency the position is priced in.'),
            'quantity' => $str('Decimal STRING with up to 8 places, e.g. `"1.25"`. A string rather than a number so a fractional holding is not handed to a float.', ['pattern' => '^\\d+(\\.\\d{1,8})?$']),
            'avg_buy_price' => $int('Per-unit price, INTEGER in minor units.', ['minimum' => 0]),
            'current_price' => $int('Per-unit price, INTEGER in minor units.', ['minimum' => 0]),
            'notes' => $str('', ['maxLength' => 5000]),
        ], ['name', 'kind', 'currency']),
        'responses' => [201 => $res('Created.', $data($R('Investment'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/investments/{id}' => ['id' => 'getInvestment', 'tag' => 'Investment', 'summary' => 'Get an investment',
        'responses' => [200 => $res('The position with its trades.', $data($R('Investment'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/investments/{id}/performance' => ['id' => 'getInvestmentPerformance', 'tag' => 'Investment', 'summary' => 'Investment performance',
        'responses' => [200 => $res('Cost basis, value, realised and unrealised profit, and ROI.', $data(['type' => 'object', 'properties' => [
            'investment_id' => ['type' => 'string'], 'quantity' => ['type' => 'string'],
            'avg_buy_price' => $R('Money'), 'current_price' => ['oneOf' => [$R('Money'), ['type' => 'null']]],
            'cost_basis' => $R('Money'), 'current_value' => $R('Money'),
            'realized_profit' => $R('Money'), 'unrealized_profit' => $R('Money'), 'total_profit' => $R('Money'),
            'roi' => ['type' => 'number'],
        ]])), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/investments/{id}/trades' => ['id' => 'recordInvestmentTrade', 'tag' => 'Investment', 'summary' => 'Record a trade',
        'description' => 'Buys move the weighted-average cost; sells never do. Selling more than is held '
            .'is refused with `insufficient_quantity`. Honours the `Idempotency-Key` header, which takes '
            .'precedence over the body field.',
        'body' => $body([
            'id' => $clientId,
            'action' => $enum(['buy', 'sell', 'dividend', 'fee', 'split']),
            'quantity' => $str('Decimal STRING, up to 8 places.', ['pattern' => '^\\d+(\\.\\d{1,8})?$']),
            'price' => $int('Per-unit price, INTEGER in minor units.', ['minimum' => 0]),
            'fee' => $int('INTEGER in minor units.', ['minimum' => 0]),
            'currency' => $currencyIn('Defaults to the position currency; a mismatch is refused rather than converted.'),
            'fx_rate' => $num('Rate to the workspace base currency.', ['exclusiveMinimum' => 0]),
            'occurred_at' => $dt(),
            'notes' => $str('', ['maxLength' => 5000]),
            'transaction_id' => $ulidIn('Link to an existing ledger transaction.'),
            'idempotency_key' => $str('Fallback for clients that cannot set the header.', ['maxLength' => 64]),
        ], ['action']),
        'responses' => [201 => $res('Recorded.', $data($R('InvestmentTransaction'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],

    // =========================================================== Assets

    'GET /api/v1/assets' => ['id' => 'listAssets', 'tag' => 'Assets', 'summary' => 'List assets',
        'query' => ['kind' => $q('', ['type' => 'string', 'enum' => ['house', 'land', 'car', 'gold', 'watch', 'art', 'nft', 'other']], 'car')],
        'responses' => [200 => $res('Assets and their combined value.', $listMeta($R('Asset'), ['total_value' => $R('MoneyBrief')]))]],
    'POST /api/v1/assets' => ['id' => 'createAsset', 'tag' => 'Assets', 'summary' => 'Create an asset',
        'body' => $body([
            'id' => $clientId, 'name' => $str('', ['maxLength' => 120]),
            'kind' => $enum(['house', 'land', 'car', 'gold', 'watch', 'art', 'nft', 'other']),
            'currency' => $currencyIn(),
            'purchase_price' => $int('INTEGER in minor units.', ['minimum' => 0]),
            'purchase_date' => $date(),
            'current_value' => $int('INTEGER in minor units. Defaults to the purchase price.', ['minimum' => 0]),
            'salvage_value' => $int('INTEGER in minor units. Must not exceed `purchase_price`.', ['minimum' => 0]),
            'depreciation_method' => $enum(['none', 'linear', 'declining']),
            'depreciation_rate' => $num('Declining-balance rate, strictly between 0 and 1. Required for `declining`.', ['exclusiveMinimum' => 0, 'exclusiveMaximum' => 1]),
            'useful_life_years' => $int('Required for `linear`.', ['minimum' => 1, 'maximum' => 200]),
            'insurance_provider' => $str('', ['maxLength' => 120]),
            'insurance_expires_at' => $date(),
            'notes' => $str('', ['maxLength' => 5000]),
        ], ['name', 'kind', 'currency', 'purchase_price', 'purchase_date']),
        'responses' => [201 => $res('Created.', $data($R('Asset'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/assets/{id}' => ['id' => 'getAsset', 'tag' => 'Assets', 'summary' => 'Get an asset',
        'responses' => [200 => $res('The asset.', $data($R('Asset'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/assets/{id}/depreciation' => ['id' => 'getAssetDepreciation', 'tag' => 'Assets', 'summary' => 'Depreciation schedule',
        'description' => 'Year-by-year schedule. Over the full life the depreciation sums to '
            .'`purchase_price - salvage_value` and the book value never falls below salvage.',
        'responses' => [200 => $res('The schedule.', $listMeta($R('DepreciationRow'), [
            'method' => ['type' => 'string'], 'useful_life_years' => ['type' => ['integer', 'null']],
            'depreciable_base' => $R('Money'), 'book_value' => $R('Money'),
        ])), 404 => ['$ref' => '#/components/responses/NotFound']]],

    // =========================================================== Buildings

    'GET /api/v1/buildings' => ['id' => 'listBuildings', 'tag' => 'Buildings', 'summary' => 'List buildings',
        'responses' => [200 => $res('Buildings.', $list($R('Building')))]],
    'POST /api/v1/buildings' => ['id' => 'createBuilding', 'tag' => 'Buildings', 'summary' => 'Create a building',
        'body' => $body([
            'id' => $clientId, 'name' => $str('', ['maxLength' => 120]),
            'address' => $str('', ['maxLength' => 255]),
            'charge_formula' => $enum(['fixed', 'per_area', 'per_resident', 'mixed'], 'How a period total is divided between units.'),
            'fund_account_id' => $ulidIn('Ledger account holding the building fund.'),
            'currency' => $currencyIn('Currency of the fund.'),
        ], ['name', 'charge_formula']),
        'responses' => [201 => $res('Created.', $data($R('Building'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/buildings/{building}' => ['id' => 'getBuilding', 'tag' => 'Buildings', 'summary' => 'Get a building',
        'responses' => [200 => $res('The building.', $data($R('Building'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'PATCH /api/v1/buildings/{building}' => ['id' => 'updateBuilding', 'tag' => 'Buildings', 'summary' => 'Update a building',
        'body' => $body([
            'name' => $str('', ['maxLength' => 120]), 'address' => $str('', ['maxLength' => 255]),
            'charge_formula' => $enum(['fixed', 'per_area', 'per_resident', 'mixed']),
            'fund_account_id' => $ulidIn('Ledger account holding the building fund.'),
        ]),
        'responses' => [200 => $res('Updated.', $data($R('Building'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/buildings/{building}/units' => ['id' => 'listBuildingUnits', 'tag' => 'Buildings', 'summary' => 'List units',
        'responses' => [200 => $res('Units.', $listMeta($R('BuildingUnit'), ['total' => ['type' => 'integer']]))]],
    'POST /api/v1/buildings/{building}/units' => ['id' => 'createBuildingUnit', 'tag' => 'Buildings', 'summary' => 'Create a unit',
        'body' => $body([
            'id' => $clientId, 'unit_no' => $str('', ['maxLength' => 32]),
            'area_m2' => $num('Square metres. Parsed digit by digit rather than through a float, because a rounding error here is multiplied across every unit.', ['minimum' => 0]),
            'residents_count' => $int('', ['minimum' => 0]),
            'owner_name' => $str('', ['maxLength' => 120]), 'owner_contact' => $str('', ['maxLength' => 120]),
            'tenant_name' => $str('', ['maxLength' => 120]), 'tenant_contact' => $str('', ['maxLength' => 120]),
            'share_factor' => $num('Manual weighting used by the `mixed` formula.', ['minimum' => 0]),
            'is_occupied' => $bool(),
        ], ['unit_no']),
        'responses' => [201 => $res('Created.', $data($R('BuildingUnit'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'PATCH /api/v1/buildings/{building}/units/{unit}' => ['id' => 'updateBuildingUnit', 'tag' => 'Buildings', 'summary' => 'Update a unit',
        'body' => $body([
            'unit_no' => $str('', ['maxLength' => 32]), 'area_m2' => $num('', ['minimum' => 0]),
            'residents_count' => $int('', ['minimum' => 0]),
            'owner_name' => $str('', ['maxLength' => 120]), 'owner_contact' => $str('', ['maxLength' => 120]),
            'tenant_name' => $str('', ['maxLength' => 120]), 'tenant_contact' => $str('', ['maxLength' => 120]),
            'share_factor' => $num('', ['minimum' => 0]), 'is_occupied' => $bool(),
        ]),
        'responses' => [200 => $res('Updated.', $data($R('BuildingUnit'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'DELETE /api/v1/buildings/{building}/units/{unit}' => ['id' => 'deleteBuildingUnit', 'tag' => 'Buildings', 'summary' => 'Delete a unit',
        'responses' => [204 => $res('Deleted.'), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/buildings/{building}/charges' => ['id' => 'listBuildingCharges', 'tag' => 'Buildings', 'summary' => 'List charges',
        'query' => [
            'period' => $q('`YYYY-MM`.', ['type' => 'string'], '2026-07'),
            'status' => $q('', ['type' => 'string', 'enum' => ['unpaid', 'partial', 'paid']], 'unpaid'),
            'unit_id' => $q('', ['type' => 'string'], ''),
            'outstanding' => $q('Only charges with something still owed.', ['type' => 'boolean'], '1'),
        ],
        'responses' => [200 => $res('Charges, with billed and collected totals. Both totals are null when the list is empty.', $listMeta($R('BuildingCharge'), [
            'count' => ['type' => 'integer'],
            'billed' => ['oneOf' => [$R('Money'), ['type' => 'null']]],
            'collected' => ['oneOf' => [$R('Money'), ['type' => 'null']]],
        ]))]],
    'POST /api/v1/buildings/{building}/charges/issue' => ['id' => 'issueBuildingCharges', 'tag' => 'Buildings', 'summary' => 'Issue charges for a period',
        'description' => 'Divides `total` across the units using the building\'s formula. The parts sum '
            .'to `total` exactly. Re-issuing a period that already has charges answers **200** with '
            .'`meta.already_issued: true` instead of creating a second set — the safe answer to a '
            .'double-tap.',
        'body' => $body([
            'period' => $str('`YYYY-MM`.', ['pattern' => '^\\d{4}-(0[1-9]|1[0-2])$']),
            'total' => $amountIn('Amount to divide across the units.'),
            'currency' => $currencyIn(),
            'due_date' => $date(),
        ], ['period', 'total', 'currency']),
        'responses' => [
            200 => $res('Charges already existed for this period; nothing was created.', $listMeta($R('BuildingCharge'), [
                'period' => ['type' => 'string'], 'count' => ['type' => 'integer'],
                'already_issued' => ['type' => 'boolean'], 'total' => $R('Money'),
            ])),
            201 => $res('Issued.', $listMeta($R('BuildingCharge'), [
                'period' => ['type' => 'string'], 'count' => ['type' => 'integer'],
                'already_issued' => ['type' => 'boolean'], 'total' => $R('Money'),
            ])),
            403 => ['$ref' => '#/components/responses/Forbidden'],
        ]],
    'POST /api/v1/building-charges/{charge}/pay' => ['id' => 'payBuildingCharge', 'tag' => 'Buildings', 'summary' => 'Pay a charge',
        'description' => 'Partial payments are allowed; paying more than is outstanding is refused with '
            .'`overpayment`. Honours the `Idempotency-Key` header, which takes precedence over the body field.',
        'pathDesc' => ['charge' => 'Charge ULID.'],
        'body' => $body([
            'amount' => $amountIn('May be a partial payment.'),
            'currency' => $currencyIn(),
            'paid_at' => $dt(), 'reference' => $str('', ['maxLength' => 255]),
            'idempotency_key' => $str('', ['maxLength' => 64]),
        ], ['amount', 'currency']),
        'responses' => [200 => $res('Paid.', $data($R('BuildingCharge'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/buildings/{building}/expenses' => ['id' => 'listBuildingExpenses', 'tag' => 'Buildings', 'summary' => 'List building expenses',
        'responses' => [200 => $res('Expenses.', $list($R('BuildingExpense')))]],
    'POST /api/v1/buildings/{building}/expenses' => ['id' => 'createBuildingExpense', 'tag' => 'Buildings', 'summary' => 'Record a building expense',
        'body' => $body([
            'amount' => $amountIn(), 'currency' => $currencyIn(),
            'category_id' => ['type' => ['string', 'null']],
            'occurred_at' => $dt(), 'description' => $str('', ['maxLength' => 255]),
        ], ['amount', 'currency']),
        'responses' => [201 => $res('Recorded.', $data($R('BuildingExpense'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/buildings/{building}/reports/debtors' => ['id' => 'getBuildingDebtors', 'tag' => 'Buildings', 'summary' => 'Debtors report',
        'query' => ['period' => $q('`YYYY-MM`. Omit for all outstanding periods.', ['type' => 'string'], '2026-07')],
        'responses' => [200 => $res('Units with something owed.', $listMeta(['type' => 'object', 'properties' => [
            'unit_id' => ['type' => 'string'], 'unit_no' => ['type' => 'string'],
            'owner_name' => ['type' => ['string', 'null']], 'tenant_name' => ['type' => ['string', 'null']],
            'charges_count' => ['type' => 'integer'], 'oldest_period' => ['type' => ['string', 'null']],
            'owed' => $R('Money'),
        ]], [
            'period' => ['type' => ['string', 'null']], 'debtor_count' => ['type' => 'integer'],
            'totals' => ['type' => 'object', 'description' => 'Keyed by currency code, since a building can bill in more than one.', 'additionalProperties' => $R('Money')],
        ]))]],
    'GET /api/v1/buildings/{building}/reports/fund' => ['id' => 'getBuildingFund', 'tag' => 'Buildings', 'summary' => 'Fund report',
        'responses' => [200 => $res('Fund position.', $dataMeta(['type' => 'object', 'properties' => [
            'building_id' => ['type' => 'string'], 'fund_account_id' => ['type' => ['string', 'null']],
            'balance' => ['oneOf' => [$R('Money'), ['type' => 'null']]],
            'billed' => $R('Money'), 'collected' => $R('Money'),
            'outstanding' => $R('Money'), 'expenses' => $R('Money'),
        ]], ['unpaid_charges' => ['type' => 'integer']]))]],

    // =========================================================== Business

    'GET /api/v1/contacts' => ['id' => 'listContacts', 'tag' => 'Business', 'summary' => 'List contacts',
        'query' => [
            'type' => $q('', ['type' => 'string', 'enum' => ['customer', 'supplier', 'employee', 'other']], 'customer'),
            'q' => $q('Substring match over name, phone and email.', ['type' => 'string'], ''),
            'per_page' => $perPage, 'page' => $page,
        ],
        'responses' => [200 => $res('A page of contacts.', $paged($R('Contact')))]],
    'POST /api/v1/contacts' => ['id' => 'createContact', 'tag' => 'Business', 'summary' => 'Create a contact',
        'body' => $body([
            'id' => $clientId,
            'type' => $enum(['customer', 'supplier', 'employee', 'other']),
            'name' => $str('', ['maxLength' => 160]),
            'phone' => $str('', ['maxLength' => 32]),
            'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 160],
            'tax_id' => $str('', ['maxLength' => 64]),
            'address' => $str('', ['maxLength' => 1000]),
        ], ['type', 'name']),
        'responses' => [201 => $res('Created.', $data($R('Contact'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/contacts/{id}' => ['id' => 'getContact', 'tag' => 'Business', 'summary' => 'Get a contact',
        'responses' => [200 => $res('The contact.', $data($R('Contact'))), 404 => ['$ref' => '#/components/responses/NotFound']]],

    'GET /api/v1/projects' => ['id' => 'listProjects', 'tag' => 'Business', 'summary' => 'List projects',
        'query' => ['status' => $q('', ['type' => 'string', 'enum' => ['planned', 'active', 'paused', 'completed', 'cancelled']], 'active')],
        'responses' => [200 => $res('Projects.', $list($R('Project')))]],
    'POST /api/v1/projects' => ['id' => 'createProject', 'tag' => 'Business', 'summary' => 'Create a project',
        'body' => $body([
            'id' => $clientId, 'name' => $str('', ['maxLength' => 160]),
            'contact_id' => ['type' => ['string', 'null']],
            'status' => $enum(['planned', 'active', 'paused', 'completed', 'cancelled'], 'Defaults to `active`.'),
            'budget_amount' => $int('INTEGER in minor units. Defaults to 0.', ['minimum' => 0]),
            'currency' => $currencyIn('Currency of `budget_amount`.'),
            'starts_at' => $date(), 'ends_at' => $date(),
        ], ['name', 'currency']),
        'responses' => [201 => $res('Created.', $data($R('Project'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/projects/{id}' => ['id' => 'getProject', 'tag' => 'Business', 'summary' => 'Get a project',
        'responses' => [200 => $res('The project.', $data($R('Project'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/projects/{id}/profitability' => ['id' => 'getProjectProfitability', 'tag' => 'Business', 'summary' => 'Project profitability',
        'description' => 'Separates invoiced from ledger-recorded amounts, because they answer different '
            .'questions — what was billed versus what actually moved.',
        'responses' => [200 => $res('Profitability.', $data(['type' => 'object', 'properties' => [
            'project_id' => ['type' => 'string'], 'project_name' => ['type' => 'string'],
            'status' => ['type' => 'string'], 'currency' => ['type' => 'string'],
            'invoiced_income' => $R('Money'), 'invoiced_expense' => $R('Money'),
            'ledger_income' => $R('Money'), 'ledger_expense' => $R('Money'),
            'income' => $R('Money'), 'expense' => $R('Money'),
            'profit' => $R('Money'), 'budget' => $R('Money'),
        ]])), 404 => $res('`project_not_found`.', $R('Error'))]],

    'GET /api/v1/invoices' => ['id' => 'listInvoices', 'tag' => 'Business', 'summary' => 'List invoices',
        'query' => [
            'direction' => $q('', ['type' => 'string', 'enum' => ['sale', 'purchase']], 'sale'),
            'status' => $q('', ['type' => 'string', 'enum' => ['draft', 'sent', 'partial', 'paid', 'overdue', 'void']], 'sent'),
            'contact_id' => $q('', ['type' => 'string'], ''),
            'project_id' => $q('', ['type' => 'string'], ''),
            'open' => $q('Only invoices with an outstanding balance.', ['type' => 'boolean'], '1'),
            'from' => $q('`issue_date >=`.', ['type' => 'string', 'format' => 'date'], '2026-07-01'),
            'to' => $q('`issue_date <=`.', ['type' => 'string', 'format' => 'date'], '2026-07-31'),
            'per_page' => $perPage, 'page' => $page,
        ],
        'responses' => [200 => $res('A page of invoices.', $paged($R('Invoice')))]],
    'POST /api/v1/invoices' => ['id' => 'createInvoice', 'tag' => 'Business', 'summary' => 'Create an invoice',
        'description' => 'Totals are computed server-side from the lines and re-checked before the '
            .'invoice is stored; a mismatch is a `500 inconsistent_invoice_totals` rather than a saved '
            .'invoice that does not add up.',
        'body' => $body([
            'id' => $clientId,
            'number' => $str('Allocated automatically when omitted. A duplicate is `409 duplicate_invoice_number`.', ['maxLength' => 64]),
            'direction' => $enum(['sale', 'purchase']),
            'contact_id' => ['type' => ['string', 'null']],
            'project_id' => ['type' => ['string', 'null']],
            'issue_date' => $date(), 'due_date' => $date(),
            'currency' => $currencyIn('Currency of every amount on this invoice.'),
            'fx_rate' => $num('Rate to the workspace base currency.', ['exclusiveMinimum' => 0]),
            'discount' => $int('Header discount, INTEGER in minor units, allocated across the lines. Must not exceed the subtotal.', ['minimum' => 0]),
            'status' => $enum(['draft', 'sent']),
            'notes' => $str('', ['maxLength' => 5000]),
            'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 200, 'items' => ['type' => 'object', 'required' => ['description', 'quantity', 'unit_price'], 'properties' => [
                'description' => ['type' => 'string', 'maxLength' => 255],
                'quantity' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'unit_price' => ['type' => 'integer', 'minimum' => 0, 'description' => 'INTEGER in minor units.'],
                'discount' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Per-line discount, INTEGER in minor units. May not exceed the line total.'],
                'tax_rate' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'Percentage, applied per line.'],
                'sort_order' => ['type' => 'integer', 'minimum' => 0],
            ]]],
        ], ['direction', 'currency', 'items']),
        'responses' => [201 => $res('Created.', $data($R('Invoice'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 409 => ['$ref' => '#/components/responses/Conflict']]],
    'GET /api/v1/invoices/{id}' => ['id' => 'getInvoice', 'tag' => 'Business', 'summary' => 'Get an invoice',
        'responses' => [200 => $res('The invoice with items, contact, project and payments.', $data($R('Invoice'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/invoices/{id}/pay' => ['id' => 'payInvoice', 'tag' => 'Business', 'summary' => 'Pay an invoice',
        'description' => 'Paying more than is outstanding is refused with `payment_exceeds_balance`.',
        'body' => $body([
            'id' => $clientId,
            'invoice_id' => $ulidIn('Redundant here — the path already identifies the invoice.'),
            'account_id' => $ulidIn('Account the money moves through.'),
            'category_id' => ['type' => ['string', 'null']],
            'amount' => $amountIn('May be a partial payment.'),
            'currency' => $currencyIn('Defaults to the invoice currency; a mismatch is refused.'),
            'paid_at' => $dt(),
            'method' => $enum(['cash', 'bank', 'card', 'cheque', 'online', 'other']),
            'reference' => $str('', ['maxLength' => 255]),
        ], ['account_id', 'amount']),
        'responses' => [201 => $res('Paid, with the updated invoice in `meta.invoice`.', $dataMeta($R('Payment'), ['invoice' => $R('Invoice')])), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/invoices/{id}/void' => ['id' => 'voidInvoice', 'tag' => 'Business', 'summary' => 'Void an invoice',
        'description' => 'An invoice that already has payments cannot be voided (`invoice_has_payments`) '
            .'— reverse the payments first, so the ledger and the invoice never disagree.',
        'body' => $body(['reason' => $str('Free text, stored with the void.')]),
        'bodyRequired' => false,
        'responses' => [200 => $res('Voided.', $data($R('Invoice'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],

    'GET /api/v1/payments' => ['id' => 'listPayments', 'tag' => 'Business', 'summary' => 'List payments',
        'query' => [
            'invoice_id' => $q('', ['type' => 'string'], ''), 'contact_id' => $q('', ['type' => 'string'], ''),
            'account_id' => $q('', ['type' => 'string'], ''),
            'from' => $q('`paid_at >=`.', ['type' => 'string', 'format' => 'date'], '2026-07-01'),
            'to' => $q('`paid_at <=`.', ['type' => 'string', 'format' => 'date'], '2026-07-31'),
            'per_page' => $perPage, 'page' => $page,
        ],
        'responses' => [200 => $res('A page of payments.', $paged($R('Payment')))]],
    'POST /api/v1/payments' => ['id' => 'createPayment', 'tag' => 'Business', 'summary' => 'Record a payment',
        'body' => $body([
            'id' => $clientId,
            'invoice_id' => $ulidIn('Invoice being paid. Required on this endpoint.'),
            'account_id' => $ulidIn('Account the money moves through.'),
            'category_id' => ['type' => ['string', 'null']],
            'amount' => $amountIn(), 'currency' => $currencyIn(),
            'paid_at' => $dt(),
            'method' => $enum(['cash', 'bank', 'card', 'cheque', 'online', 'other']),
            'reference' => $str('', ['maxLength' => 255]),
        ], ['invoice_id', 'account_id', 'amount']),
        'responses' => [201 => $res('Recorded.', $data($R('Payment'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/payments/{id}' => ['id' => 'getPayment', 'tag' => 'Business', 'summary' => 'Get a payment',
        'responses' => [200 => $res('The payment.', $data($R('Payment'))), 404 => ['$ref' => '#/components/responses/NotFound']]],

    // =========================================================== Travel

    'GET /api/v1/trips' => ['id' => 'listTrips', 'tag' => 'Travel', 'summary' => 'List trips',
        'responses' => [200 => $res('Trips with their members.', $list($R('Trip')))]],
    'POST /api/v1/trips' => ['id' => 'createTrip', 'tag' => 'Travel', 'summary' => 'Create a trip',
        'body' => $body([
            'id' => $clientId, 'name' => $str('', ['maxLength' => 120]),
            'destination' => $str('', ['maxLength' => 180]),
            'starts_at' => $dt(), 'ends_at' => $dt(),
            'base_currency' => $currencyIn('Currency the trip settles in.'),
            'members' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object', 'required' => ['display_name'], 'properties' => [
                'id' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
                'user_id' => ['type' => ['integer', 'null'], 'description' => 'Only for travellers who are also Finora users.'],
                'display_name' => ['type' => 'string', 'maxLength' => 120],
                'weight' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000, 'description' => 'Relative share for weighted splits. Defaults to 1.'],
            ]]],
        ], ['name', 'base_currency']),
        'responses' => [201 => $res('Created.', $data($R('Trip'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/trips/{trip}' => ['id' => 'getTrip', 'tag' => 'Travel', 'summary' => 'Get a trip',
        'responses' => [200 => $res('The trip with its members.', $data($R('Trip'))), 404 => $res('`trip_not_found`.', $R('Error'))]],
    'POST /api/v1/trips/{trip}/members' => ['id' => 'addTripMember', 'tag' => 'Travel', 'summary' => 'Add a trip member',
        'body' => $body([
            'id' => $clientId, 'user_id' => ['type' => ['integer', 'null']],
            'display_name' => $str('', ['maxLength' => 120]),
            'weight' => $int('', ['minimum' => 0, 'maximum' => 1000]),
        ], ['display_name']),
        'responses' => [201 => $res('Added.', $data($R('TripMember'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/trips/{trip}/expenses' => ['id' => 'listTripExpenses', 'tag' => 'Travel', 'summary' => 'List split expenses',
        'query' => [
            'payer_member_id' => $q('', ['type' => 'string'], ''),
            'from' => $q('`occurred_at >=`.', ['type' => 'string', 'format' => 'date'], '2026-07-01'),
            'to' => $q('`occurred_at <=`.', ['type' => 'string', 'format' => 'date'], '2026-07-31'),
            'per_page' => $perPage, 'page' => $page,
        ],
        'responses' => [200 => $res('A page of expenses.', $paged($R('SplitExpense')))]],
    'POST /api/v1/trips/{trip}/expenses' => ['id' => 'createTripExpense', 'tag' => 'Travel', 'summary' => 'Record a split expense',
        'description' => "How the split is computed depends on `mode`:\n\n"
            ."- `equal` — divided evenly between the participants\n"
            ."- `percent` — `participants[].percent`, which must sum to 100\n"
            ."- `weight` — `participants[].weight`\n"
            ."- `exact` — `participants[].amount`, which must sum to `amount`\n\n"
            .'In every mode the shares sum to `amount` exactly: a remainder is handed out one minor '
            .'unit at a time rather than rounded away, so splitting 100 three ways gives 34/33/33.',
        'body' => $body([
            'id' => $clientId,
            'payer_member_id' => $ulidIn('Trip member who actually paid.'),
            'amount' => $amountIn(),
            'currency' => $currencyIn('Defaults to the trip base currency.'),
            'fx_rate' => $num('', ['exclusiveMinimum' => 0]),
            'category_id' => ['type' => ['string', 'null']],
            'occurred_at' => $dt(),
            'description' => $str('', ['maxLength' => 255]),
            'latitude' => $num('', ['minimum' => -90, 'maximum' => 90]),
            'longitude' => $num('', ['minimum' => -180, 'maximum' => 180]),
            'mode' => $enum(['equal', 'percent', 'weight', 'exact'], 'Defaults to `equal`.'),
            'participants' => ['type' => 'array', 'maxItems' => 100, 'items' => ['type' => 'object', 'required' => ['member_id'], 'properties' => [
                'member_id' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
                'percent' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'For `percent` mode. Must sum to 100 across participants.'],
                'weight' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000, 'description' => 'For `weight` mode.'],
                'amount' => ['type' => 'integer', 'minimum' => 0, 'description' => 'For `exact` mode. INTEGER in minor units; must sum to the expense `amount`.'],
            ]], 'description' => 'Omit to split between every trip member.'],
        ], ['payer_member_id', 'amount']),
        'responses' => [201 => $res('Recorded, with the computed shares.', $data($R('SplitExpense'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'DELETE /api/v1/trips/{trip}/expenses/{expense}' => ['id' => 'deleteTripExpense', 'tag' => 'Travel', 'summary' => 'Delete a split expense',
        'responses' => [204 => $res('Deleted.'), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/trips/{trip}/settlement/preview' => ['id' => 'previewTripSettlement', 'tag' => 'Travel', 'summary' => 'Preview settlement',
        'description' => 'Who owes what, and the smallest set of transfers that clears it — at most '
            .'n-1 transfers, by greedy largest-creditor pairing. Nothing is written.',
        'responses' => [200 => $res('Balances and proposed transfers.', $data(['type' => 'object', 'properties' => [
            'balances' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'member_id' => ['type' => 'string'], 'display_name' => ['type' => ['string', 'null']],
                'balance' => ['allOf' => [$R('Money')], 'description' => 'Negative means this member owes.'],
            ]]],
            'transfers' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'from_member_id' => ['type' => 'string'], 'to_member_id' => ['type' => 'string'], 'amount' => $R('Money'),
            ]]],
        ]])), 404 => $res('`trip_not_found`.', $R('Error'))]],
    'POST /api/v1/trips/{trip}/settlement' => ['id' => 'settleTrip', 'tag' => 'Travel', 'summary' => 'Settle a trip',
        'description' => 'Records the transfers from the preview. Every member ends at zero — a residue '
            .'would be `unbalanced_trip`, a 500, because the arithmetic is not allowed to leak.',
        'body' => $body(['settled_at' => $dt('Defaults to now.')]),
        'bodyRequired' => false,
        'responses' => [201 => $res('Settled.', $list($R('Settlement'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => $res('`trip_not_found`.', $R('Error'))]],

    // =========================================================== Documents

    'GET /api/v1/documents' => ['id' => 'listDocuments', 'tag' => 'Documents', 'summary' => 'List documents',
        'query' => [
            'kind' => $q('', ['type' => 'string', 'enum' => ['receipt', 'invoice', 'contract', 'photo', 'voice', 'video', 'archive', 'other']], 'receipt'),
            'attached_type' => $q('Alias, not a class name. An unrecognised value is `422 forbidden_attachable_type`.', ['type' => 'string', 'enum' => ['transaction', 'category', 'account']], 'transaction'),
            'attached_id' => $q('', ['type' => 'string'], ''),
            'per_page' => $perPage, 'page' => $page,
        ],
        'responses' => [200 => $res('A page of documents.', $paged($R('Document')))]],
    'POST /api/v1/documents' => ['id' => 'uploadDocument', 'tag' => 'Documents', 'summary' => 'Upload a document',
        'description' => 'Multipart upload. Size and mime type are both whitelisted; the limits come '
            .'from `config/documents.php` rather than being hard-coded here, so this spec states the '
            .'shape and the server states the ceiling.',
        'bodyType' => 'multipart/form-data',
        'body' => $body([
            'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'The file. Mime type must be on the configured allow-list (PDF, Office documents, plain text/CSV, common image, audio and video types, and common archives).'],
            'kind' => $enum(['receipt', 'invoice', 'contract', 'photo', 'voice', 'video', 'archive', 'other']),
            'ocr_status' => $enum(['pending', 'processing', 'done', 'failed', 'skipped'], 'Seed the OCR state, e.g. `skipped` for a file that needs no text extraction.'),
        ], ['file']),
        'responses' => [201 => $res('Stored.', $data($R('Document'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/documents/{id}' => ['id' => 'getDocument', 'tag' => 'Documents', 'summary' => 'Get a document',
        'responses' => [200 => $res('The document with its attachments.', $data($R('Document'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'DELETE /api/v1/documents/{id}' => ['id' => 'deleteDocument', 'tag' => 'Documents', 'summary' => 'Delete a document',
        'responses' => [204 => $res('Deleted.'), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/documents/{id}/attach' => ['id' => 'attachDocument', 'tag' => 'Documents', 'summary' => 'Attach a document to a record',
        'body' => $body([
            'type' => $enum(['transaction', 'category', 'account'], 'Alias, not a class name. Anything else is `422 forbidden_attachable_type` — the whitelist exists so a crafted request cannot attach a file to an arbitrary model.'),
            'id' => $str('Id of the record to attach to.', ['maxLength' => 64]),
        ], ['type', 'id']),
        'responses' => [201 => $res('Attached.', $data($R('Document'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => $res('`attachable_not_found`.', $R('Error'))]],
    'POST /api/v1/documents/{id}/detach' => ['id' => 'detachDocument', 'tag' => 'Documents', 'summary' => 'Detach a document from a record',
        'body' => $body([
            'type' => $enum(['transaction', 'category', 'account']),
            'id' => $str('', ['maxLength' => 64]),
        ], ['type', 'id']),
        'responses' => [204 => $res('Detached.'), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],

    // =========================================================== Family

    'GET /api/v1/family/members' => ['id' => 'listFamilyMembers', 'tag' => 'Family', 'summary' => 'List family members',
        'responses' => [200 => $res('Members.', $list($R('FamilyMember')))]],
    'POST /api/v1/family/members' => ['id' => 'createFamilyMember', 'tag' => 'Family', 'summary' => 'Add a family member',
        'body' => $body([
            'id' => $clientId, 'user_id' => ['type' => ['integer', 'null']],
            'display_name' => $str('', ['maxLength' => 120]),
            'role' => $enum(['parent', 'child', 'other']),
            'birth_date' => $date(),
            'monthly_allowance' => $int('INTEGER in minor units. Returned as a `Money` object.', ['minimum' => 0]),
            'currency' => $currencyIn('Currency of the allowance and cap.'),
            'spending_cap' => $int('INTEGER in minor units. Returned as a `Money` object.', ['minimum' => 0]),
            'account_id' => $ulidIn('Ledger account this member spends from.'),
        ], ['display_name', 'role', 'currency']),
        'responses' => [201 => $res('Added.', $data($R('FamilyMember'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => $res('`account_not_found`.', $R('Error'))]],
    'GET /api/v1/family/members/{member}' => ['id' => 'getFamilyMember', 'tag' => 'Family', 'summary' => 'Get a family member',
        'responses' => [200 => $res('The member.', $data($R('FamilyMember'))), 404 => $res('`family_member_not_found`.', $R('Error'))]],
    'PATCH /api/v1/family/members/{member}' => ['id' => 'updateFamilyMember', 'tag' => 'Family', 'summary' => 'Update a family member',
        'description' => 'Currency cannot be changed after creation — an allowance history stated in two '
            .'currencies would not be comparable.',
        'body' => $body([
            'display_name' => $str('', ['maxLength' => 120]),
            'role' => $enum(['parent', 'child', 'other']),
            'birth_date' => $date(),
            'monthly_allowance' => $int('INTEGER in minor units.', ['minimum' => 0]),
            'spending_cap' => $int('INTEGER in minor units.', ['minimum' => 0]),
            'account_id' => $ulidIn('Ledger account this member spends from.'),
        ]),
        'responses' => [200 => $res('Updated.', $data($R('FamilyMember'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => $res('`family_member_not_found`.', $R('Error'))]],
    'GET /api/v1/family/spending' => ['id' => 'getFamilySpending', 'tag' => 'Family', 'summary' => 'Spending against caps',
        'query' => [
            'period' => $q('`YYYY-MM`. Defaults to the current period.', ['type' => 'string'], '2026-07'),
            'member_id' => $q('Restrict to one member.', ['type' => 'string'], ''),
        ],
        'responses' => [200 => $res('Spending per member.', $listMeta($R('MemberSpending'), [
            'period' => ['type' => 'string'], 'from' => ['type' => 'string', 'format' => 'date-time'], 'to' => ['type' => 'string', 'format' => 'date-time'],
        ]))]],
    'GET /api/v1/family/allowances' => ['id' => 'listAllowances', 'tag' => 'Family', 'summary' => 'List allowance payments',
        'query' => [
            'period' => $q('`YYYY-MM`.', ['type' => 'string'], '2026-07'),
            'member_id' => $q('', ['type' => 'string'], ''),
        ],
        'responses' => [200 => $res('Allowance payments.', $list($R('AllowancePayment')))]],
    'POST /api/v1/family/members/{member}/allowance' => ['id' => 'payAllowance', 'tag' => 'Family', 'summary' => 'Pay an allowance',
        'description' => 'Paying the same member twice for the same period is refused with '
            .'`409 allowance_already_paid`, which is what stops a double-tap becoming a double payment.',
        'body' => $body([
            'payer_member_id' => $ulidIn('Family member paying — must not be the recipient.'),
            'period' => $str('`YYYY-MM`.', ['minLength' => 7, 'maxLength' => 7]),
            'amount' => $amountIn('Defaults to the member\'s configured monthly allowance.'),
            'currency' => $currencyIn('Required when `amount` is given.'),
            'paid_at' => $dt(),
        ], ['payer_member_id', 'period']),
        'responses' => [201 => $res('Paid.', $data($R('AllowancePayment'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => $res('`family_member_not_found`.', $R('Error')), 409 => ['$ref' => '#/components/responses/Conflict']]],

    // =========================================================== Recurring

    'GET /api/v1/recurring-rules' => ['id' => 'listRecurringRules', 'tag' => 'Recurring', 'summary' => 'List recurring rules',
        'responses' => [200 => $res('Rules, soonest due first.', $list($R('RecurringRule')))]],
    'POST /api/v1/recurring-rules' => ['id' => 'createRecurringRule', 'tag' => 'Recurring', 'summary' => 'Create a recurring rule',
        'body' => $body([
            'id' => $clientId, 'name' => $str('', ['maxLength' => 120]),
            'template' => ['type' => 'object', 'required' => ['type', 'account_id', 'amount', 'currency'], 'description' => 'The transaction to post each period. `amount` here is a BARE INTEGER in minor units, with `currency` beside it.', 'properties' => [
                'type' => ['type' => 'string', 'enum' => ['income', 'expense', 'transfer']],
                'account_id' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
                'counter_account_id' => ['type' => ['string', 'null']],
                'category_id' => ['type' => ['string', 'null']],
                'amount' => ['type' => 'integer', 'minimum' => 1, 'description' => 'INTEGER in minor units.'],
                'currency' => ['type' => 'string', 'enum' => $currencies],
                'description' => ['type' => 'string', 'maxLength' => 255],
                'payee' => ['type' => 'string', 'maxLength' => 255],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
            'frequency' => $enum(['daily', 'weekly', 'monthly', 'yearly']),
            'interval' => $int('Every N periods. Default 1.', ['minimum' => 1, 'maximum' => 365]),
            'day_of_month' => $int('', ['minimum' => 1, 'maximum' => 31]),
            'day_of_week' => $int('0-6.', ['minimum' => 0, 'maximum' => 6]),
            'starts_at' => $dt(), 'ends_at' => $dt(),
            'auto_post' => $bool('Post automatically rather than only proposing. Default true.'),
            'is_paused' => $bool('Default false.'),
        ], ['template', 'frequency', 'starts_at']),
        'responses' => [201 => $res('Created.', $data($R('RecurringRule'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'PATCH /api/v1/recurring-rules/{rule}' => ['id' => 'updateRecurringRule', 'tag' => 'Recurring', 'summary' => 'Update a recurring rule',
        'description' => 'Only the fields that can safely change after postings exist. The template and '
            .'the schedule are immutable — editing them would retroactively change what the already '
            .'posted occurrences were supposed to be.',
        'body' => $body([
            'name' => $str('', ['maxLength' => 120]),
            'ends_at' => $dt(),
            'auto_post' => $bool(), 'is_paused' => $bool(),
        ]),
        'responses' => [200 => $res('Updated.', $data($R('RecurringRule'))), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => $res('`recurring_rule_not_found`.', $R('Error'))]],
    'DELETE /api/v1/recurring-rules/{rule}' => ['id' => 'deleteRecurringRule', 'tag' => 'Recurring', 'summary' => 'Delete a recurring rule',
        'responses' => [204 => $res('Deleted. Already-posted occurrences are untouched.'), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => $res('`recurring_rule_not_found`.', $R('Error'))]],

    // =========================================================== AI

    'POST /api/v1/ai/drafts' => ['id' => 'createAiDraft', 'tag' => 'AI', 'summary' => 'Draft a transaction from text',
        'description' => 'Turns a sentence into a proposed transaction. It is a PROPOSAL — nothing '
            .'reaches the ledger until `POST /ai/drafts/{id}/confirm`.',
        'body' => $body(['text' => $str('Natural language, e.g. "45 lira on lunch yesterday".', ['maxLength' => 2000])], ['text']),
        'responses' => [201 => $res('Drafted.', $data($R('AiDraft'))), 403 => $res('`ai_disabled`, or the caller cannot write.', $R('Error')), 503 => ['$ref' => '#/components/responses/ServiceUnavailable']]],
    'GET /api/v1/ai/drafts' => ['id' => 'listAiDrafts', 'tag' => 'AI', 'summary' => 'List drafts',
        'query' => [
            'status' => $q('', ['type' => 'string', 'enum' => ['pending', 'confirmed', 'discarded']], 'pending'),
            'limit' => $q('Default 50, maximum 200.', ['type' => 'integer', 'maximum' => 200], '50'),
        ],
        'responses' => [200 => $res('Drafts.', $list($R('AiDraft')))]],
    'GET /api/v1/ai/drafts/{id}' => ['id' => 'getAiDraft', 'tag' => 'AI', 'summary' => 'Get a draft',
        'responses' => [200 => $res('The draft.', $data($R('AiDraft'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/ai/drafts/{id}/confirm' => ['id' => 'confirmAiDraft', 'tag' => 'AI', 'summary' => 'Confirm a draft into the ledger',
        'description' => 'The only path from a draft to a real transaction. Any field sent here '
            .'overrides what was extracted, so a user can correct the machine before anything is '
            .'written. Confirming twice is `409 ai_draft_already_resolved`.',
        'body' => $confirmBody, 'bodyRequired' => false,
        'responses' => [201 => $res('Posted.', $data($R('Transaction'))), 404 => ['$ref' => '#/components/responses/NotFound'], 409 => ['$ref' => '#/components/responses/Conflict']]],
    'POST /api/v1/ai/drafts/{id}/discard' => ['id' => 'discardAiDraft', 'tag' => 'AI', 'summary' => 'Discard a draft',
        'responses' => [200 => $res('Discarded.', $data($R('AiDraft'))), 404 => ['$ref' => '#/components/responses/NotFound'], 409 => ['$ref' => '#/components/responses/Conflict']]],
    'POST /api/v1/ai/receipts' => ['id' => 'queueReceiptDraft', 'tag' => 'AI', 'summary' => 'Queue receipt OCR',
        'description' => 'Takes a document id — upload the file first with `POST /documents`. This is '
            .'not a multipart endpoint. Work is queued; poll `GET /ai/drafts` for the result, or '
            .'listen on the workspace job channel.',
        'body' => $body(['document_id' => $str('Id of an already-uploaded document.', ['maxLength' => 64])], ['document_id']),
        'responses' => [202 => $res('Queued.', $data(['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['queued']], 'document_id' => ['type' => 'string'],
        ]])), 503 => ['$ref' => '#/components/responses/ServiceUnavailable']]],
    'POST /api/v1/ai/voice-notes' => ['id' => 'queueVoiceDraft', 'tag' => 'AI', 'summary' => 'Queue voice transcription',
        'description' => 'Takes a document id — upload the audio first with `POST /documents`. Not multipart.',
        'body' => $body(['document_id' => $str('Id of an already-uploaded audio document.', ['maxLength' => 64])], ['document_id']),
        'responses' => [202 => $res('Queued.', $data(['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['queued']], 'document_id' => ['type' => 'string'],
        ]])), 503 => ['$ref' => '#/components/responses/ServiceUnavailable']]],
    'POST /api/v1/ai/chat' => ['id' => 'aiChat', 'tag' => 'AI', 'summary' => 'Ask the assistant',
        'description' => 'Tool-calling chat over the workspace\'s own data. The workspace id is injected '
            .'server-side and stripped from any tool arguments the model produces, so a prompt injected '
            .'into a transaction description cannot redirect a query at another workspace. `redacted` '
            .'lists what was removed.',
        'body' => $body([
            'question' => $str('', ['maxLength' => 2000]),
            'conversation_id' => $str('Continue an existing conversation.', ['maxLength' => 64]),
        ], ['question']),
        'responses' => [200 => $res('The answer and how it was reached.', $data(['type' => 'object', 'properties' => [
            'conversation_id' => ['type' => 'string'], 'answer' => ['type' => 'string'],
            'tool_calls' => ['type' => 'array', 'items' => ['type' => 'string']],
            'sources' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'redacted' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Arguments stripped from the model\'s tool calls before execution.'],
            'provider' => ['type' => 'string'],
        ]])), 403 => $res('`ai_disabled`.', $R('Error')), 503 => ['$ref' => '#/components/responses/ServiceUnavailable']]],
    'GET /api/v1/ai/conversations/{id}' => ['id' => 'getAiConversation', 'tag' => 'AI', 'summary' => 'Get a conversation',
        'responses' => [200 => $res('The conversation and its messages.', $data(['type' => 'object', 'properties' => [
            'id' => ['type' => 'string'], 'title' => ['type' => ['string', 'null']],
            'messages' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'string'],
                'role' => ['type' => 'string', 'enum' => ['user', 'assistant', 'tool']],
                'content' => ['type' => ['string', 'null']],
                'tool_name' => ['type' => ['string', 'null']],
                'created_at' => ['type' => 'string', 'format' => 'date-time'],
            ]]],
        ]])), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'DELETE /api/v1/ai/conversations/{id}' => ['id' => 'deleteAiConversation', 'tag' => 'AI', 'summary' => 'Delete a conversation',
        'responses' => [204 => $res('Deleted.'), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/ai/insights' => ['id' => 'listAiInsights', 'tag' => 'AI', 'summary' => 'List insights',
        'query' => ['type' => $q('', ['type' => 'string', 'enum' => ['spending_composition', 'period_change', 'spending_anomaly', 'cashflow_forecast']], 'spending_anomaly')],
        'responses' => [200 => $res('Active insights, newest first.', $list($R('AiInsight')))]],
    'POST /api/v1/ai/insights/generate' => ['id' => 'generateAiInsights', 'tag' => 'AI', 'summary' => 'Regenerate insights',
        'description' => 'Recomputes the statistical insights now rather than waiting for the schedule.',
        'responses' => [200 => $res('The freshly computed insights.', $list($R('AiInsight')))]],
    'POST /api/v1/ai/insights/{id}/dismiss' => ['id' => 'dismissAiInsight', 'tag' => 'AI', 'summary' => 'Dismiss an insight',
        'responses' => [200 => $res('Dismissed.', $data($R('AiInsight'))), 404 => ['$ref' => '#/components/responses/NotFound']]],

    // =========================================================== Capture

    'POST /api/v1/capture/text' => ['id' => 'captureText', 'tag' => 'Capture', 'summary' => 'Capture free text',
        'description' => 'Produces a draft. Capture NEVER posts to the ledger directly — confirmation is a separate call.',
        'body' => $body(['text' => $str('', ['maxLength' => 2000])], ['text']),
        'responses' => [201 => $res('Captured.', $data($R('CaptureMessage')))]],
    'POST /api/v1/capture/sms' => ['id' => 'captureSms', 'tag' => 'Capture', 'summary' => 'Capture a bank SMS',
        'description' => 'Matched against the bank SMS patterns in configuration. A message that matches '
            .'nothing is still stored, with `status: "unparsed"`, and still answered `201` — losing it '
            .'would be worse than keeping it for review.',
        'body' => $body([
            'sender' => $str('Sender id or number.', ['maxLength' => 191]),
            'body' => $str('Raw message text.', ['maxLength' => 2000]),
            'received_at' => $dt(),
        ], ['sender', 'body', 'received_at']),
        'responses' => [201 => $res('Captured.', $data($R('CaptureMessage')))]],
    'POST /api/v1/capture/qr' => ['id' => 'captureQr', 'tag' => 'Capture', 'summary' => 'Capture a QR payload',
        'body' => $body(['payload' => $str('Raw scanned payload.', ['maxLength' => 4000])], ['payload']),
        'responses' => [201 => $res('Captured.', $data($R('CaptureMessage')))]],
    'POST /api/v1/capture/email' => ['id' => 'captureEmailWebhook', 'tag' => 'Capture', 'summary' => 'Inbound email webhook',
        'description' => "Called by the mail provider, not by a client — it uses a shared secret rather "
            ."than a Sanctum token, and takes no `X-Workspace-Id`; the workspace is resolved from the "
            ."recipient ingest alias.\n\n"
            ."Authenticate with EITHER:\n"
            ."- `X-Capture-Signature: sha256=<hmac>` — HMAC-SHA256 of the RAW request body, keyed with "
            ."the shared secret. Preferred; checked first when present.\n"
            ."- `X-Capture-Secret: <secret>` — the plain shared secret.\n\n"
            .'If no secret is configured the endpoint fails closed with `503 '
            .'capture_webhook_not_configured` rather than accepting anything.',
        'security' => [],
        'headers' => [
            'X-Capture-Signature' => ['schema' => ['type' => 'string'], 'description' => '`sha256=` followed by the hex HMAC-SHA256 of the raw body. Compared in constant time.'],
            'X-Capture-Secret' => ['schema' => ['type' => 'string'], 'description' => 'Plain shared secret. Used only when no signature header is present.'],
        ],
        'body' => $body([
            'to' => $str('Recipient ingest address; identifies the workspace.', ['maxLength' => 191]),
            'from' => $str('', ['maxLength' => 191]),
            'subject' => $str('', ['maxLength' => 500]),
            'text' => $str('Plain-text body.', ['maxLength' => 20000]),
            'message_id' => $str('Used to detect a redelivered message.', ['maxLength' => 191]),
            'received_at' => $dt(),
            'attachments' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object', 'required' => ['filename', 'content'], 'properties' => [
                'filename' => ['type' => 'string', 'maxLength' => 191],
                'content_type' => ['type' => ['string', 'null'], 'maxLength' => 191],
                'content' => ['type' => 'string', 'description' => 'Attachment bytes inline as a string — this endpoint is JSON, not multipart.'],
            ]]],
        ], ['to']),
        'responses' => [
            201 => $res('Captured.', $data($R('CaptureMessage'))),
            401 => $res('`capture_webhook_unauthorized` — bad or missing signature/secret.', $R('Error')),
            404 => $res('`capture_unknown_recipient` — no ingest alias matches `to`.', $R('Error')),
            413 => $res('`capture_attachment_too_large`.', $R('Error')),
            503 => $res('`capture_webhook_not_configured` — no secret set, so the endpoint refuses everything.', $R('Error')),
        ]],
    'GET /api/v1/capture/messages' => ['id' => 'listCaptureMessages', 'tag' => 'Capture', 'summary' => 'List captured messages',
        'query' => [
            'channel' => $q('', ['type' => 'string', 'enum' => ['text', 'sms', 'qr', 'email']], 'sms'),
            'status' => $q('', ['type' => 'string', 'enum' => ['parsed', 'unparsed', 'duplicate', 'rejected']], 'parsed'),
            'limit' => $q('Default 50, maximum 200.', ['type' => 'integer', 'maximum' => 200], '50'),
        ],
        'responses' => [200 => $res('Messages.', $list($R('CaptureMessage')))]],
    'GET /api/v1/capture/messages/{id}' => ['id' => 'getCaptureMessage', 'tag' => 'Capture', 'summary' => 'Get a captured message',
        'responses' => [200 => $res('The message and its draft.', $data($R('CaptureMessage'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/capture/messages/{id}/confirm' => ['id' => 'confirmCaptureMessage', 'tag' => 'Capture', 'summary' => 'Confirm a captured message',
        'description' => 'Posts the message\'s draft to the ledger, with any corrections sent here taking '
            .'precedence. A message with nothing to confirm is `422 capture_nothing_to_confirm`.',
        'body' => $confirmBody, 'bodyRequired' => false,
        'responses' => [201 => $res('Posted.', $data($R('Transaction'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/capture/ingest-aliases' => ['id' => 'listIngestAliases', 'tag' => 'Capture', 'summary' => 'List ingest aliases',
        'responses' => [200 => $res('Aliases. The addresses themselves are not returned — only a hash is stored.', $list($R('IngestAlias')))]],
    'POST /api/v1/capture/ingest-aliases' => ['id' => 'createIngestAlias', 'tag' => 'Capture', 'summary' => 'Create an ingest alias',
        'description' => 'The full `address` is returned ONCE, here. Only its hash is stored, so it '
            .'cannot be shown again — copy it now. Requires a role that can manage others.',
        'body' => $body(['label' => $str('Your own note about what this address is for.', ['maxLength' => 64])]),
        'bodyRequired' => false,
        'responses' => [201 => $res('Created. `address` is present on this response only.', $data($R('IngestAlias'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'DELETE /api/v1/capture/ingest-aliases/{id}' => ['id' => 'deleteIngestAlias', 'tag' => 'Capture', 'summary' => 'Revoke an ingest alias',
        'responses' => [204 => $res('Revoked.'), 403 => ['$ref' => '#/components/responses/Forbidden'], 404 => ['$ref' => '#/components/responses/NotFound']]],

    // =========================================================== Alerts

    'GET /api/v1/alerts' => ['id' => 'listAlerts', 'tag' => 'Alerts', 'summary' => 'List alerts',
        'description' => 'Scoped to the calling user, not the whole workspace.',
        'query' => [
            'unread' => $q('Only alerts not yet marked read.', ['type' => 'boolean'], '1'),
            'type' => $q('', ['type' => 'string'], ''),
        ],
        'responses' => [200 => $res('Alerts, newest first.', $list($R('Alert')))]],
    'POST /api/v1/alerts/{id}/read' => ['id' => 'markAlertRead', 'tag' => 'Alerts', 'summary' => 'Mark an alert read',
        'responses' => [200 => $res('Marked read.', $data($R('Alert'))), 404 => $res('`alert_not_found`.', $R('Error'))]],
    'GET /api/v1/alerts/rules' => ['id' => 'listAlertRules', 'tag' => 'Alerts', 'summary' => 'List alert rules',
        'responses' => [200 => $res('Rules.', $list($R('AlertRule')))]],
    'POST /api/v1/alerts/rules' => ['id' => 'createAlertRule', 'tag' => 'Alerts', 'summary' => 'Create an alert rule',
        'body' => $body([
            'id' => $clientId,
            'type' => $enum(['check_due', 'installment_due', 'budget_threshold', 'low_balance']),
            'config' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Type-specific settings, e.g. a balance floor for `low_balance`.'],
            'channels' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['database', 'push', 'email', 'sms', 'telegram', 'whatsapp']], 'description' => '`database` is appended by the server whatever you send, so an alert always has somewhere to land even if every external channel fails.'],
            'lead_days' => $int('How far ahead of the due date to fire.', ['minimum' => 0, 'maximum' => 365]),
            'is_active' => $bool(),
        ], ['type']),
        'responses' => [201 => $res('Created.', $data($R('AlertRule')))]],
    'DELETE /api/v1/alerts/rules/{id}' => ['id' => 'deleteAlertRule', 'tag' => 'Alerts', 'summary' => 'Delete an alert rule',
        'responses' => [204 => $res('Deleted.'), 404 => $res('`alert_rule_not_found`.', $R('Error'))]],
    'GET /api/v1/alerts/preferences' => ['id' => 'getAlertPreferences', 'tag' => 'Alerts', 'summary' => 'Get alert preferences',
        'responses' => [200 => $res('Preferences. All six channel keys are always present.', $data($R('AlertPreferences')))]],
    'PUT /api/v1/alerts/preferences' => ['id' => 'updateAlertPreferences', 'tag' => 'Alerts', 'summary' => 'Update alert preferences',
        'description' => 'Alerts falling inside quiet hours are DEFERRED until the window ends, not dropped.',
        'body' => $body([
            'channels' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean'], 'description' => 'Per-channel on/off. Partial updates are allowed.'],
            'quiet_hours_start' => ['type' => ['string', 'null'], 'pattern' => '^\\d{2}:\\d{2}$', 'description' => '`HH:MM`.'],
            'quiet_hours_end' => ['type' => ['string', 'null'], 'pattern' => '^\\d{2}:\\d{2}$', 'description' => '`HH:MM`.'],
            'timezone' => ['type' => ['string', 'null'], 'description' => 'IANA timezone the quiet window is evaluated in.'],
        ]),
        'responses' => [200 => $res('Updated.', $data($R('AlertPreferences')))]],

    // =========================================================== Billing

    'GET /api/v1/billing/plans' => ['id' => 'listPlans', 'tag' => 'Billing', 'summary' => 'List plans',
        'responses' => [200 => $res('Public plans. Note `price` uses the `amount` key rather than `value` — see `BillingMoney`.', $list($R('Plan')))]],
    'GET /api/v1/billing/subscription' => ['id' => 'getSubscription', 'tag' => 'Billing', 'summary' => 'Get the subscription',
        'description' => 'Returns three top-level keys, not the usual one: the subscription, what it '
            .'entitles the workspace to, and what the workspace is currently using. Every limit in the '
            .'product is read from `entitlements`, so this is the authoritative answer to "can I".',
        'responses' => [200 => $res('Subscription, entitlements and usage.', ['type' => 'object', 'properties' => [
            'data' => $R('Subscription'),
            'entitlements' => $R('Entitlements'),
            'usage' => ['type' => 'object', 'description' => 'Always exactly these four counters.', 'properties' => [
                'workspaces' => ['type' => 'integer'], 'accounts' => ['type' => 'integer'],
                'members' => ['type' => 'integer'], 'budgets' => ['type' => 'integer'],
            ]],
        ]])]],
    'POST /api/v1/billing/subscription' => ['id' => 'changePlan', 'tag' => 'Billing', 'summary' => 'Change plan',
        'description' => 'Downgrading below what the workspace already uses is refused with '
            .'`downgrade_blocked`, listing each violated limit — the alternative would be deleting the '
            .'user\'s data to make it fit.',
        'body' => $body(['plan_code' => $str('', ['maxLength' => 32])], ['plan_code']),
        'responses' => [
            200 => $res('Changed.', $data($R('Subscription'))),
            402 => $res('`payment_failed`.', $R('Error')),
            404 => $res('`unknown_plan`.', $R('Error')),
        ]],
    'POST /api/v1/billing/subscription/trial' => ['id' => 'startTrial', 'tag' => 'Billing', 'summary' => 'Start a trial',
        'body' => $body([
            'plan_code' => $str('', ['maxLength' => 32]),
            'days' => $int('Trial length.', ['minimum' => 1, 'maximum' => 365]),
        ], ['plan_code']),
        'responses' => [
            201 => $res('Trial started.', $data($R('Subscription'))),
            404 => $res('`unknown_plan`.', $R('Error')),
        ]],
    'POST /api/v1/billing/subscription/cancel' => ['id' => 'cancelSubscription', 'tag' => 'Billing', 'summary' => 'Cancel the subscription',
        'responses' => [200 => $res('Cancelled. Access continues until `renews_at`.', $data($R('Subscription'))), 404 => $res('`subscription_not_found`.', $R('Error'))]],
    'GET /api/v1/billing/invoices' => ['id' => 'listBillingInvoices', 'tag' => 'Billing', 'summary' => 'List subscription invoices',
        'description' => 'Finora\'s own invoices to the customer. Not to be confused with `/invoices`, '
            .'which are the customer\'s invoices to THEIR customers.',
        'responses' => [200 => $res('Invoices, newest first.', $list($R('SubscriptionInvoice')))]],

    // =========================================================== Core

    'POST /api/v1/auth/register' => ['id' => 'register', 'tag' => 'Core', 'summary' => 'Register',
        'description' => 'Creates the user, seeds a personal workspace with a wallet account and a '
            .'three-level category tree, and returns a token. The `token` is shown once.',
        'body' => $body([
            'name' => $str('', ['maxLength' => 120]),
            'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 190],
            'password' => $str('', ['minLength' => 8]),
            'locale' => $enum(['fa', 'en', 'tr', 'ar'], 'Default `fa`.'),
            'base_currency' => $str('Base currency for the seeded workspace. Default `IRR`.', ['maxLength' => 8]),
        ], ['name', 'email', 'password']),
        'responses' => [201 => $res('Registered.', $data($R('AuthSuccess')))]],
    'POST /api/v1/auth/login' => ['id' => 'login', 'tag' => 'Core', 'summary' => 'Sign in',
        'description' => "**Two different 200 bodies.** With two-factor confirmed, this returns a "
            ."CHALLENGE and no token — exchange it at `POST /auth/2fa/verify`. Without, it returns the "
            ."token and the user's workspaces. Check for `two_factor_required` before reading `token`.",
        'headers' => ['X-Device-Name' => ['schema' => ['type' => 'string', 'maxLength' => 120], 'description' => 'Names the issued token, so a user can tell their devices apart when revoking one.']],
        'body' => $body([
            'email' => ['type' => 'string', 'format' => 'email'],
            'password' => $str(),
        ], ['email', 'password']),
        'responses' => [
            200 => $res('Signed in, OR a two-factor challenge.', ['oneOf' => [
                $data($R('AuthSuccess')),
                $data(['type' => 'object', 'required' => ['two_factor_required', 'challenge'], 'properties' => [
                    'two_factor_required' => ['type' => 'boolean', 'enum' => [true]],
                    'challenge' => ['type' => 'string', 'description' => 'Short-lived; exchange at `/auth/2fa/verify`.'],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time'],
                ]]),
            ]]),
            401 => $res('`invalid_credentials`. The same answer for an unknown email and a wrong password, so the endpoint cannot be used to discover which addresses are registered.', $R('Error')),
            403 => $res('`account_pending_deletion` — the account is inside its deletion grace period. `details.purge_after` says until when; `POST /me/restore` cancels it.', $R('Error')),
        ]],
    'POST /api/v1/auth/logout' => ['id' => 'logout', 'tag' => 'Core', 'summary' => 'Sign out',
        'description' => 'Revokes the current access token only, leaving the user\'s other devices signed in.',
        'responses' => [204 => $res('Signed out.')]],
    'GET /api/v1/me' => ['id' => 'getMe', 'tag' => 'Core', 'summary' => 'Current user',
        'responses' => [200 => $res('The authenticated user.', $data($R('User')))]],
    'GET /api/v1/workspaces' => ['id' => 'listWorkspaces', 'tag' => 'Core', 'summary' => 'List workspaces',
        'description' => 'Deliberately NOT workspace-scoped: this is how a client discovers which ids it '
            .'may send in `X-Workspace-Id`.',
        'responses' => [200 => $res('Workspaces the user belongs to, with the caller\'s role in each.', $list($R('Workspace')))]],
    'POST /api/v1/workspaces' => ['id' => 'createWorkspace', 'tag' => 'Core', 'summary' => 'Create a workspace',
        'body' => $body([
            'name' => $str('', ['maxLength' => 120]),
            'type' => $enum(['personal', 'business', 'building', 'travel', 'family', 'store']),
            'base_currency' => $currencyIn('Every report and every `base` amount in this workspace is stated in this currency.'),
            'locale' => $enum(['fa', 'en', 'tr', 'ar']),
            'timezone' => $str('Default `Asia/Tehran`.', ['maxLength' => 64]),
        ], ['name', 'type', 'base_currency']),
        'responses' => [201 => $res('Created, with the caller as owner.', $data($R('Workspace')))]],
    'GET /api/v1/members' => ['id' => 'listMembers', 'tag' => 'Core', 'summary' => 'List members',
        'responses' => [200 => $res('Members.', $list($R('Member'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'PATCH /api/v1/members/{id}' => ['id' => 'updateMemberRole', 'tag' => 'Core', 'summary' => 'Change a member\'s role',
        'description' => 'The owner cannot be demoted (`cannot_change_owner_role`) and nobody can be '
            .'promoted to a second owner (`cannot_invite_as_owner`) — otherwise an admin could lock the '
            .'owner out of their own books.',
        'body' => $body(['role' => $enum(['owner', 'admin', 'accountant', 'member', 'viewer'])], ['role']),
        'responses' => [200 => $res('Updated.', $data($R('Member'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'DELETE /api/v1/members/{id}' => ['id' => 'removeMember', 'tag' => 'Core', 'summary' => 'Remove a member',
        'responses' => [204 => $res('Removed.'), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'GET /api/v1/invitations' => ['id' => 'listInvitations', 'tag' => 'Core', 'summary' => 'List invitations',
        'responses' => [200 => $res('Invitations. `token` is never included here — only at creation.', $list($R('Invitation')))]],
    'POST /api/v1/invitations' => ['id' => 'createInvitation', 'tag' => 'Core', 'summary' => 'Invite someone',
        'description' => 'The plaintext `token` is returned ONCE, on this response. Only its hash is '
            .'stored, so a leaked database hands nobody a working link.',
        'body' => $body([
            'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 190, 'description' => 'The accepting user\'s email must match this — holding the link is deliberately not enough.'],
            'role' => $enum(['admin', 'accountant', 'member', 'viewer'], 'Never `owner`: `cannot_invite_as_owner`.'),
        ], ['email', 'role']),
        'responses' => [
            201 => $res('Invited. Copy `token` now.', $data($R('Invitation'))),
            409 => $res('`already_member` or `already_invited`.', $R('Error')),
        ]],
    'DELETE /api/v1/invitations/{id}' => ['id' => 'revokeInvitation', 'tag' => 'Core', 'summary' => 'Revoke an invitation',
        'responses' => [204 => $res('Revoked.'), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/invitations/{token}/accept' => ['id' => 'acceptInvitation', 'tag' => 'Core', 'summary' => 'Accept an invitation',
        'description' => 'Not workspace-scoped — this is how someone gets INTO a workspace, so it cannot '
            .'require already being in one; the token identifies the workspace. An unknown token answers '
            .'exactly as a revoked one does, so the endpoint cannot be used to probe which tokens exist.',
        'path' => ['token' => ['type' => 'string']],
        'pathDesc' => ['token' => 'The plaintext invitation token from the invitation email or link.'],
        'responses' => [
            201 => $res('Joined.', $data(['type' => 'object', 'properties' => [
                'workspace_id' => ['type' => 'string'], 'role' => ['type' => 'string'],
            ]])),
            403 => $res('`invitation_email_mismatch` — the invitation was issued to a different address.', $R('Error')),
            404 => $res('`invitation_invalid` — unknown, revoked or expired. Identical for all three.', $R('Error')),
        ]],

    // =========================================================== Security

    'GET /api/v1/auth/2fa' => ['id' => 'getTwoFactorStatus', 'tag' => 'Security', 'summary' => 'Two-factor status',
        'responses' => [200 => $res('Status.', $data($R('TwoFactorStatus')))]],
    'POST /api/v1/auth/2fa/enable' => ['id' => 'enableTwoFactor', 'tag' => 'Security', 'summary' => 'Begin enabling two-factor',
        'description' => 'Issues a TOTP secret. It is NOT active until confirmed with a code — otherwise '
            .'a mis-scanned QR would lock the user out of their own account.',
        'responses' => [
            200 => $res('Secret issued, awaiting confirmation.', $data(['type' => 'object', 'properties' => [
                'secret' => ['type' => 'string', 'description' => 'Base32 TOTP secret. Encrypted at rest.'],
                'otpauth_uri' => ['type' => 'string', 'description' => 'Render as a QR code.'],
                'confirmed' => ['type' => 'boolean', 'enum' => [false]],
            ]])),
            409 => $res('`two_factor_already_enabled`.', $R('Error')),
        ]],
    'POST /api/v1/auth/2fa/confirm' => ['id' => 'confirmTwoFactor', 'tag' => 'Security', 'summary' => 'Confirm two-factor',
        'description' => 'Activates the secret and returns the recovery codes. They are shown ONCE — '
            .'codes that scroll away unread are the same as no codes at all on the day the phone is lost.',
        'body' => $body(['code' => $str('Current TOTP code.', ['maxLength' => 16])], ['code']),
        'responses' => [
            200 => $res('Confirmed.', $data(['type' => 'object', 'properties' => [
                'confirmed_at' => ['type' => 'string', 'format' => 'date-time'],
                'recovery_codes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Single-use. Returned once and never again.'],
            ]])),
            409 => $res('`two_factor_already_enabled` or `two_factor_not_enabled`.', $R('Error')),
        ]],
    'POST /api/v1/auth/2fa/disable' => ['id' => 'disableTwoFactor', 'tag' => 'Security', 'summary' => 'Disable two-factor',
        'description' => 'Requires the password or a current code — at least one. Turning off a second '
            .'factor must not be easier than turning it on.',
        'body' => $body([
            'password' => $str('Account password.'),
            'code' => $str('Current TOTP or recovery code.', ['maxLength' => 32]),
        ]),
        'responses' => [
            200 => $res('Disabled.', $data(['type' => 'object', 'properties' => ['two_factor_enabled' => ['type' => 'boolean', 'enum' => [false]]]])),
            409 => $res('`two_factor_not_enabled`.', $R('Error')),
        ]],
    'POST /api/v1/auth/2fa/verify' => ['id' => 'verifyTwoFactor', 'tag' => 'Security', 'summary' => 'Exchange a two-factor challenge for a token',
        'description' => 'The second half of sign-in when two-factor is on. Accepts a TOTP code or a '
            .'single-use recovery code.',
        'body' => $body([
            'challenge' => $str('The challenge string from `POST /auth/login`.'),
            'code' => $str('TOTP or recovery code.', ['maxLength' => 32]),
        ], ['challenge', 'code']),
        'responses' => [
            200 => $res('Signed in.', $data($R('AuthSuccess'))),
            401 => $res('`two_factor_challenge_invalid` — expired or unknown challenge.', $R('Error')),
        ]],
    'POST /api/v1/auth/forgot-password' => ['id' => 'forgotPassword', 'tag' => 'Security', 'summary' => 'Request a password reset',
        'description' => 'Answers identically for a known and an unknown address. A different answer '
            .'would tell an attacker which addresses are registered.',
        'body' => $body(['email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 190]], ['email']),
        'responses' => [200 => $res('Always this, whether or not the address exists.', $data(['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['password_reset_link_sent']],
        ]]))]],
    'POST /api/v1/auth/reset-password' => ['id' => 'resetPassword', 'tag' => 'Security', 'summary' => 'Reset a password',
        'description' => 'Revokes every existing token on success, so a session an attacker already has '
            .'does not survive the reset.',
        'body' => $body([
            'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 190],
            'token' => $str('Reset token from the email.'),
            'password' => $str('New password.', ['minLength' => 8]),
        ], ['email', 'token', 'password']),
        'responses' => [200 => $res('Reset.', $data(['type' => 'object', 'properties' => [
            'status' => ['type' => 'string', 'enum' => ['password_reset']],
        ]]))]],

    // =========================================================== Sync

    'POST /api/v1/sync/push' => ['id' => 'syncPush', 'tag' => 'Sync', 'summary' => 'Push local changes',
        'description' => "Uploads a batch of offline changes and answers with one verdict per change, in "
            ."order.\n\n"
            ."The rule this endpoint exists to enforce: **a disagreement about an amount, currency, "
            ."account or date is never auto-resolved.** It comes back as `status: \"conflict\"` with the "
            ."server's version in `server_payload`, for a person to settle. Non-financial fields merge "
            ."last-write-wins.\n\n"
            .'`base_version` is the `version` the client last saw. A stale one is what makes a conflict '
            .'detectable at all.',
        'headers' => ['X-Device-Id' => ['schema' => ['type' => 'string', 'maxLength' => 64], 'description' => 'Calling device. The `device_id` body field is read first, then this header.']],
        'body' => $body([
            'device_id' => $str('Calling device. Falls back to the `X-Device-Id` header.', ['maxLength' => 64]),
            'changes' => ['type' => 'array', 'maxItems' => 500, 'description' => 'Batch size is capped by `sync.max_batch` (500 by default).', 'items' => [
                'type' => 'object',
                'required' => ['entity', 'id', 'op', 'base_version'],
                'properties' => [
                    'entity' => ['type' => 'string', 'enum' => ['transaction', 'account', 'category', 'budget'], 'description' => 'Only whitelisted entities sync.'],
                    'id' => ['type' => 'string', 'maxLength' => 64, 'description' => 'The client-generated ULID.'],
                    'op' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                    'base_version' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Version the client last saw. 0 for a create.'],
                    'payload' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'The record\'s fields. Required unless `op` is `delete`. Money fields are bare integers in minor units with a sibling currency field.'],
                ],
            ]],
        ], ['changes']),
        'responses' => [200 => $res('One verdict per pushed change.', ['type' => 'object', 'properties' => [
            'results' => ['type' => 'array', 'items' => $R('PushVerdict')],
            'server_time' => ['type' => 'string', 'description' => '`YYYY-MM-DDTHH:MM:SSZ`. Use as the next `since`.'],
            'meta' => ['type' => 'object', 'properties' => ['device_id' => ['type' => ['string', 'null']], 'request_id' => ['type' => ['string', 'null']]]],
        ]]), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/sync/pull' => ['id' => 'syncPull', 'tag' => 'Sync', 'summary' => 'Pull server changes',
        'description' => 'Cursor-paged delta feed, totally ordered on `(updated_at, entity, id)` so no '
            .'record can be skipped or repeated across pages. Follow `next_cursor` until it is null, then '
            .'keep `server_time` as the next `since`.',
        'headers' => ['X-Device-Id' => ['schema' => ['type' => 'string', 'maxLength' => 64], 'description' => 'Calling device.']],
        'query' => [
            'since' => $q('Return changes after this instant. Omit for a full backfill. Unparseable → `422 invalid_timestamp`.', ['type' => 'string', 'format' => 'date-time'], '2026-07-25T09:30:00Z'),
            'cursor' => $q('Opaque cursor from the previous page\'s `next_cursor`. Tampered values → `422 invalid_cursor`.', ['type' => 'string'], ''),
            'limit' => $q('1-200, default 100.', ['type' => 'integer', 'maximum' => 200], '100'),
            'device_id' => $q('Calling device. Falls back to `X-Device-Id`.', ['type' => 'string'], ''),
        ],
        'responses' => [200 => $res('A page of changes.', ['type' => 'object', 'properties' => [
            'data' => ['type' => 'array', 'items' => $R('SyncChange')],
            'next_cursor' => ['type' => ['string', 'null'], 'description' => 'Null when the feed is exhausted.'],
            'server_time' => ['type' => 'string', 'description' => '`YYYY-MM-DDTHH:MM:SSZ`. The server clock, so a skewed device cannot silently miss changes.'],
            'meta' => ['type' => 'object', 'properties' => [
                'limit' => ['type' => 'integer'], 'count' => ['type' => 'integer'],
                'since' => ['type' => ['string', 'null']], 'request_id' => ['type' => ['string', 'null']],
            ]],
        ]])]],
    'GET /api/v1/devices' => ['id' => 'listDevices', 'tag' => 'Sync', 'summary' => 'List devices',
        'responses' => [200 => $res('Devices. Push tokens are never echoed.', $listMeta($R('Device'), [
            'total' => ['type' => 'integer'], 'request_id' => ['type' => ['string', 'null']],
        ]))]],
    'POST /api/v1/devices' => ['id' => 'registerDevice', 'tag' => 'Sync', 'summary' => 'Register a device',
        'description' => 'Answers **201** for a new device and **200** when an existing one re-registers, '
            .'so a client that repeats this on every launch does not accumulate duplicates.',
        'body' => $body([
            'id' => $clientId,
            'platform' => $enum(['ios', 'android', 'web', 'desktop', 'unknown']),
            'name' => $str('Human-readable device name.', ['maxLength' => 120]),
            'push_token' => $str('Stored, never returned.', ['maxLength' => 512]),
        ]),
        'bodyRequired' => false,
        'responses' => [
            200 => $res('Existing device updated.', $data($R('Device'))),
            201 => $res('Registered.', $data($R('Device'))),
        ]],
    'DELETE /api/v1/devices/{id}' => ['id' => 'revokeDevice', 'tag' => 'Sync', 'summary' => 'Revoke a device',
        'description' => 'Revokes rather than deletes, and answers **200** with the revoked record — the '
            .'row is kept so the audit trail still resolves the device that made past changes.',
        'responses' => [200 => $res('Revoked.', $data($R('Device'))), 404 => $res('`device_not_found`.', $R('Error'))]],

    // =========================================================== I18n

    'GET /api/v1/translations/{locale}' => ['id' => 'getTranslations', 'tag' => 'I18n', 'summary' => 'Get the translation dictionary',
        'description' => 'PUBLIC — no token. The sign-in screen needs its own words before anyone has a '
            .'token, and a dictionary is not secret. A key with no value is never served: it would '
            .'replace a working fallback with a blank label.',
        'security' => [],
        'path' => ['locale' => ['type' => 'string', 'enum' => ['fa', 'en', 'tr', 'ar']]],
        'pathDesc' => ['locale' => 'Locale to fetch.'],
        'query' => ['since' => $q('Return only keys changed after this instant, using the SERVER clock — a device with a skewed clock cannot miss an update forever.', ['type' => 'string', 'format' => 'date-time'], '2026-07-25T09:30:00Z')],
        'responses' => [200 => $res('Flat key/value map.', ['type' => 'object', 'properties' => [
            'data' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Keys are `key` for the `app` group and `{group}.{key}` otherwise. `{}` when the delta is empty.'],
            'meta' => ['type' => 'object', 'properties' => [
                'locale' => ['type' => 'string'], 'count' => ['type' => 'integer'],
                'checked_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Pass back as the next `since`.'],
                'version' => ['type' => ['string', 'null']],
            ]],
        ]]), 404 => $res('Unknown locale.')]],
    'PUT /api/v1/translations' => ['id' => 'upsertTranslation', 'tag' => 'I18n', 'summary' => 'Override a translation',
        'description' => 'Overrides one string for everyone, without shipping a new build.',
        'body' => $body([
            'locale' => $enum(['fa', 'en', 'tr', 'ar']),
            'group' => $str('Default `app`.', ['maxLength' => 64]),
            'key' => $str('', ['maxLength' => 191]),
            'value' => $str('', ['maxLength' => 5000]),
        ], ['locale', 'key', 'value']),
        'responses' => [200 => $res('Stored.', $data(['type' => 'object', 'properties' => [
            'locale' => ['type' => 'string'], 'group' => ['type' => 'string'],
            'key' => ['type' => 'string'], 'value' => ['type' => 'string'],
            'is_overridden' => ['type' => 'boolean'], 'updated_at' => ['type' => 'string', 'format' => 'date-time'],
        ]]))]],

    // =========================================================== Audit

    'GET /api/v1/audit-logs' => ['id' => 'listAuditLogs', 'tag' => 'Audit', 'summary' => 'Workspace audit trail',
        'description' => 'Owner and admin only. Append-only: entries cannot be edited or deleted through '
            .'any endpoint, because a trail you can quietly edit is not a trail.',
        'query' => [
            'action' => $q('', ['type' => 'string'], 'auth.login'),
            'subject_type' => $q('', ['type' => 'string'], ''), 'subject_id' => $q('', ['type' => 'string'], ''),
            'user_id' => $q('', ['type' => 'integer'], ''),
            'from' => $q('`created_at >=`.', ['type' => 'string', 'format' => 'date'], '2026-07-01'),
            'to' => $q('`created_at <=`.', ['type' => 'string', 'format' => 'date'], '2026-07-31'),
            'per_page' => $perPage, 'page' => $page,
        ],
        'responses' => [200 => $res('A page of entries.', $paged($R('AuditLogEntry'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/me/security-log' => ['id' => 'getSecurityLog', 'tag' => 'Audit', 'summary' => 'Personal security log',
        'description' => 'Sign-in, sign-out and failed sign-in for the calling user. These events carry '
            .'no workspace, which is why they live here rather than in a workspace trail.',
        'query' => ['limit' => $q('Default 50, maximum 200.', ['type' => 'integer', 'maximum' => 200], '50')],
        'responses' => [200 => $res('Entries, newest first.', $list($R('AuditLogEntry')))]],

    // =========================================================== DataOps

    'GET /api/v1/exports' => ['id' => 'listDataExports', 'tag' => 'DataOps', 'summary' => 'List data exports',
        'description' => 'There is no `GET /exports/{id}` — poll this collection and pick your row out of it.',
        'query' => ['per_page' => $q('Row limit. Default 50, maximum 200. Note this is a plain limit; no pagination meta is returned.', ['type' => 'integer', 'maximum' => 200], '50')],
        'responses' => [200 => $res('Exports.', $list($R('DataExport'))), 403 => $res('`export_forbidden` — owner and admin only.', $R('Error'))]],
    'POST /api/v1/exports' => ['id' => 'createDataExport', 'tag' => 'DataOps', 'summary' => 'Request a data export',
        'description' => 'Queues a ZIP of the whole workspace as CSV, JSON and the stored documents. '
            .'Amounts in the export are written as decimal strings, not minor units — a spreadsheet cell '
            .'reading 35000 for ₺350.00 would be worse than no export at all.',
        'responses' => [202 => $res('Queued. Poll `GET /exports`.', $data($R('DataExport'))), 403 => $res('`export_forbidden`.', $R('Error'))]],
    'GET /api/v1/exports/{id}/download' => ['id' => 'downloadDataExport', 'tag' => 'DataOps', 'summary' => 'Download a data export',
        'responses' => [
            200 => $binary('The ZIP archive, named `finora-export-{id}.zip`.', 'application/zip'),
            409 => $res('`export_not_ready` — `details.status` says which state it is in.', $R('Error')),
            410 => $res('`export_expired` — the archive has been cleaned up. Request a new one.', $R('Error')),
        ]],
    'DELETE /api/v1/me' => ['id' => 'scheduleAccountDeletion', 'tag' => 'DataOps', 'summary' => 'Schedule account deletion',
        'description' => 'Schedules deletion after a grace period rather than deleting now. `purge_after` '
            .'is the point of no return — state it to the user before asking for the password, and use '
            .'the server\'s value rather than computing it hopefully on the client. `POST /me/restore` '
            .'cancels it while the window is open.',
        'body' => $body(['password' => $str('Account password. Required.')], ['password']),
        'responses' => [
            202 => $res('Scheduled.', $data(['type' => 'object', 'properties' => [
                'status' => ['type' => 'string', 'enum' => ['deletion_scheduled']],
                'grace_days' => ['type' => 'integer'],
                'requested_at' => ['type' => 'string', 'format' => 'date-time'],
                'purge_after' => ['type' => 'string', 'format' => 'date-time', 'description' => 'After this instant the deletion is irreversible.'],
            ]])),
            403 => $res('`incorrect_password`.', $R('Error')),
            409 => $res('`deletion_already_scheduled` — `details.purge_after` carries the existing date. Treat this as the pending state, not as a dead end.', $R('Error')),
        ]],
    'POST /api/v1/me/restore' => ['id' => 'cancelAccountDeletion', 'tag' => 'DataOps', 'summary' => 'Cancel a scheduled deletion',
        'responses' => [
            200 => $res('Cancelled.', $data(['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'enum' => ['active']]]])),
            410 => $res('`deletion_window_closed` — the grace period has passed.', $R('Error')),
        ]],

    // ---- Payroll -------------------------------------------------------
    'GET /api/v1/employees' => ['id' => 'listEmployees', 'tag' => 'Payroll', 'summary' => 'List employees',
        'query' => [
            'status' => $q('Filter by employment status.', ['type' => 'string', 'enum' => ['active', 'on_leave', 'ended']], 'active'),
            'q' => $q('Match against name, job title or employee number.', ['type' => 'string'], 'Karimi'),
        ],
        'responses' => [200 => $res('A page of employees, each with the rate in force.', $paged($R('Employee')))]],
    'POST /api/v1/employees' => ['id' => 'createEmployee', 'tag' => 'Payroll', 'summary' => 'Create an employee',
        'description' => 'The optional `compensation` block sets the opening rate; omitting it creates an employee with no rate, who is then skipped by a run until one exists.',
        'body' => $body([
            'id' => $clientId,
            'employee_number' => $str('Free-form. Not allocated for you.', ['maxLength' => 64]),
            'name' => $str('', ['maxLength' => 255]),
            'job_title' => $str('', ['maxLength' => 255]),
            'email' => $str('', ['format' => 'email', 'maxLength' => 255]),
            'phone' => $str('', ['maxLength' => 32]),
            'national_id' => $str('Encrypted at rest and never returned; responses carry `has_national_id` instead.', ['maxLength' => 64]),
            'country' => $str('ISO 3166-1 alpha-2. Selects the tax rule set applied to this employee.', ['minLength' => 2, 'maxLength' => 2]),
            'status' => $enum(['active', 'on_leave', 'ended']),
            'started_on' => $date(), 'ended_on' => $date('Must not precede `started_on`.'),
            'notes' => $str('', ['maxLength' => 5000]),
            'compensation' => $body([
                'amount' => $int('INTEGER in minor units. 5000000 with TRY is ₺50,000.00.', ['minimum' => 0]),
                'currency' => $currencyIn('Currency of the rate.'),
                'period' => $enum(['monthly', 'annual', 'weekly', 'daily'], 'What the amount is per. Defaults to monthly.'),
                'effective_from' => $date(),
            ], ['amount', 'currency']),
        ], ['name', 'started_on']),
        'responses' => [201 => $res('Created.', $data($R('Employee'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/employees/{id}' => ['id' => 'getEmployee', 'tag' => 'Payroll', 'summary' => 'Get an employee',
        'responses' => [200 => $res('The employee with the full rate history.', $data($R('Employee'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'PATCH /api/v1/employees/{id}' => ['id' => 'updateEmployee', 'tag' => 'Payroll', 'summary' => 'Update an employee',
        'description' => 'Pay is not editable here — a rate change is a new compensation record, so the history stays intact and a past run stays explicable.',
        'body' => $body([
            'employee_number' => $str('', ['maxLength' => 64]), 'name' => $str('', ['maxLength' => 255]),
            'job_title' => $str('', ['maxLength' => 255]), 'email' => $str('', ['format' => 'email']),
            'phone' => $str('', ['maxLength' => 32]), 'national_id' => $str('', ['maxLength' => 64]),
            'country' => $str('', ['minLength' => 2, 'maxLength' => 2]),
            'status' => $enum(['active', 'on_leave', 'ended']),
            'started_on' => $date(), 'ended_on' => $date('Setting this ends employment; the employee is excluded from any later run.'),
            'notes' => $str('', ['maxLength' => 5000]),
        ]),
        'responses' => [200 => $res('Updated.', $data($R('Employee'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/employees/{id}/compensation' => ['id' => 'setEmployeeCompensation', 'tag' => 'Payroll', 'summary' => 'Set a new pay rate',
        'description' => 'Appends a rate effective from a date rather than overwriting the current one. A run always uses the rate in force during its period, so raising someone\'s pay never restates last month.',
        'body' => $body([
            'amount' => $int('INTEGER in minor units.', ['minimum' => 0]),
            'currency' => $currencyIn('Currency of the rate.'),
            'period' => $enum(['monthly', 'annual', 'weekly', 'daily']),
            'effective_from' => $date('Defaults to today.'),
        ], ['amount', 'currency']),
        'responses' => [201 => $res('Recorded.', $data($R('Employee'))), 404 => ['$ref' => '#/components/responses/NotFound']]],

    'GET /api/v1/payroll/tax-rules' => ['id' => 'listTaxRules', 'tag' => 'Payroll', 'summary' => 'List tax rule sets',
        'query' => ['country' => $q('ISO 3166-1 alpha-2.', ['type' => 'string'], 'TR')],
        'responses' => [200 => $res('Rule sets for this workspace.', $list($R('TaxRuleSet')))]],
    'POST /api/v1/payroll/tax-rules' => ['id' => 'createTaxRule', 'tag' => 'Payroll', 'summary' => 'Create a tax rule set',
        'description' => 'Brackets, contributions and fixed deductions are data, not code. A payslip records the name of the rule set that produced it, so amending a rule set cannot silently restate a run that has already been approved.',
        'body' => $body([
            'id' => $clientId,
            'country' => $str('ISO 3166-1 alpha-2.', ['minLength' => 2, 'maxLength' => 2]),
            'name' => $str('', ['maxLength' => 255]),
            'currency' => $currencyIn('Currency the caps and fixed amounts are expressed in.'),
            'effective_from' => $date(),
            'is_active' => $bool('Only one active set per country is used by a run.'),
            'rules' => $body([
                'tax_base' => $enum(['gross', 'gross_less_employee_contributions'], 'What the brackets are applied to.'),
                'brackets' => ['type' => 'array', 'maxItems' => 20, 'items' => $body([
                    'up_to' => $int('Upper bound, INTEGER minor units. Omit on the top bracket.', ['minimum' => 0]),
                    'rate' => $num('Percentage.', ['minimum' => 0, 'maximum' => 100]),
                ], ['rate'])],
                'employee_contributions' => ['type' => 'array', 'maxItems' => 20, 'items' => $body([
                    'code' => $str('', ['maxLength' => 64]), 'label' => $str('', ['maxLength' => 255]),
                    'rate' => $num('Percentage.', ['minimum' => 0, 'maximum' => 100]),
                    'cap' => $int('INTEGER minor units.', ['minimum' => 0]),
                ], ['code', 'rate'])],
                'employer_contributions' => ['type' => 'array', 'maxItems' => 20, 'items' => $body([
                    'code' => $str('', ['maxLength' => 64]), 'label' => $str('', ['maxLength' => 255]),
                    'rate' => $num('Percentage.', ['minimum' => 0, 'maximum' => 100]),
                    'cap' => $int('INTEGER minor units.', ['minimum' => 0]),
                ], ['code', 'rate'])],
                'fixed_deductions' => ['type' => 'array', 'maxItems' => 20, 'items' => $body([
                    'code' => $str('', ['maxLength' => 64]), 'label' => $str('', ['maxLength' => 255]),
                    'amount' => $int('INTEGER minor units.', ['minimum' => 0]),
                ], ['code', 'amount'])],
            ]),
        ], ['country', 'rules']),
        'responses' => [201 => $res('Created.', $data($R('TaxRuleSet'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],

    'GET /api/v1/payroll/runs' => ['id' => 'listPayrollRuns', 'tag' => 'Payroll', 'summary' => 'List payroll runs',
        'query' => [
            'status' => $q('Filter by state.', ['type' => 'string', 'enum' => ['draft', 'approved', 'paid']], 'draft'),
            'from' => $q('Runs whose period ends on or after this date.', ['type' => 'string', 'format' => 'date'], '2026-01-01'),
        ],
        'responses' => [200 => $res('A page of runs with their totals.', $paged($R('PayrollRun')))]],
    'POST /api/v1/payroll/runs' => ['id' => 'createPayrollRun', 'tag' => 'Payroll', 'summary' => 'Create a draft run',
        'description' => 'Calculates every payslip and posts nothing. An employee whose employment ended before the period is excluded even if listed explicitly, and someone who started or left mid-period is prorated by worked days.'."\n\n"
            .'`net` is `gross` minus `deductions`, exactly, in integer minor units. Employer contributions are not subtracted from net; they are in `employer_cost`.',
        'body' => $body([
            'id' => $clientId,
            'reference' => $str('', ['maxLength' => 64]),
            'period_start' => $date(), 'period_end' => $date('Must not precede `period_start`.'),
            'pay_date' => $date(),
            'currency' => $currencyIn('Defaults to the workspace base currency.'),
            'account_id' => $ulidIn('The account net pay is drawn from when the run is approved.'),
            'category_id' => $ulidIn('Category for the posted expense.'),
            'employee_ids' => ['type' => 'array', 'maxItems' => 1000, 'items' => ['type' => 'string', 'minLength' => 26, 'maxLength' => 26],
                'description' => 'Omit for everyone employed during the period. A leaver is excluded either way.'],
            'adjustments' => ['type' => 'object', 'description' => 'Keyed by employee id — one-off earnings and deductions for this run only.', 'additionalProperties' => $body([
                'earnings' => ['type' => 'array', 'maxItems' => 50, 'items' => $body(['code' => $str('', ['maxLength' => 64]), 'label' => $str('', ['maxLength' => 255]), 'amount' => $int('INTEGER minor units.', ['minimum' => 0])], ['amount'])],
                'deductions' => ['type' => 'array', 'maxItems' => 50, 'items' => $body(['code' => $str('', ['maxLength' => 64]), 'label' => $str('', ['maxLength' => 255]), 'amount' => $int('INTEGER minor units.', ['minimum' => 0])], ['amount'])],
            ])],
            'notes' => $str('', ['maxLength' => 5000]),
        ], ['period_start', 'period_end', 'account_id']),
        'responses' => [201 => $res('Draft created with its payslips. Nothing has been posted.', $data($R('PayrollRun'))), 403 => ['$ref' => '#/components/responses/Forbidden']]],
    'GET /api/v1/payroll/runs/{id}' => ['id' => 'getPayrollRun', 'tag' => 'Payroll', 'summary' => 'Get a payroll run',
        'responses' => [200 => $res('The run with its payslips.', $data($R('PayrollRun'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
    'POST /api/v1/payroll/runs/{id}/approve' => ['id' => 'approvePayrollRun', 'tag' => 'Payroll', 'summary' => 'Approve a run and post it',
        'description' => 'This is the step that reaches the ledger, through the same `RecordTransaction` every other module uses. It is idempotent: approving an already-approved run returns the same run with the same transaction ids and does not post again.',
        'responses' => [
            200 => $res('Approved and posted. `net_transaction_id` and `liability_transaction_id` are now set.', $data($R('PayrollRun'))),
            404 => ['$ref' => '#/components/responses/NotFound'],
            409 => $res('`payroll_run_not_approvable` — the run is already paid, or has no payslips.', $R('Error')),
        ]],
    'POST /api/v1/payroll/runs/{id}/pay' => ['id' => 'payPayrollRun', 'tag' => 'Payroll', 'summary' => 'Mark an approved run as paid',
        'body' => $body(['pay_date' => $date('Defaults to today.')]),
        'responses' => [
            200 => $res('Paid. The run is now immutable.', $data($R('PayrollRun'))),
            404 => ['$ref' => '#/components/responses/NotFound'],
            409 => $res('`payroll_run_not_payable` — the run has not been approved.', $R('Error')),
        ]],
    'DELETE /api/v1/payroll/runs/{id}' => ['id' => 'deletePayrollRun', 'tag' => 'Payroll', 'summary' => 'Delete a draft run',
        'description' => 'Only a draft can be deleted. Once a run has been approved it has reached the ledger, and the way back is a reversing entry rather than a delete.',
        'responses' => [
            204 => $res('Deleted.'),
            404 => ['$ref' => '#/components/responses/NotFound'],
            409 => $res('`payroll_run_immutable` — the run has been approved or paid.', $R('Error')),
        ]],

    'GET /api/v1/payroll/payslips/{id}' => ['id' => 'getPayslip', 'tag' => 'Payroll', 'summary' => 'Get a payslip',
        'description' => 'Lines are itemised by kind. A `contribution` is paid by the employer and is not subtracted from net — reading it as a deduction is the usual way a payslip gets misrendered.',
        'responses' => [200 => $res('The payslip with its lines and employee.', $data($R('Payslip'))), 404 => ['$ref' => '#/components/responses/NotFound']]],
];
