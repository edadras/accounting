<?php

declare(strict_types=1);

use Modules\Budget\Models\Budget;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Writers\TransactionWriter;

return [

    /*
    |--------------------------------------------------------------------------
    | Batch and page sizes
    |--------------------------------------------------------------------------
    |
    | A device that has been offline for a fortnight sends a lot at once, but an
    | unbounded batch is a denial-of-service handed to any client with a bug.
    |
    */

    'max_batch' => (int) env('SYNC_MAX_BATCH', 500),

    'default_limit' => (int) env('SYNC_DEFAULT_LIMIT', 100),

    // docs/05-api-conventions.md §6: 200 is the ceiling for every list.
    'max_limit' => 200,

    /*
    |--------------------------------------------------------------------------
    | Syncable entities
    |--------------------------------------------------------------------------
    |
    | The closed set of records a device may push and pull, keyed by the stable
    | string the wire protocol uses. A request names a key, never a class:
    | resolving a class name out of a request body would let a caller reach any
    | Eloquent model in the application, including ones with no workspace scope.
    |
    |   fields    — the columns a client owns. Anything else in a payload is
    |               dropped, so a device cannot write `workspace_id`, `version`
    |               or a derived column such as a category's materialized path.
    |   financial — the subset that is never auto-resolved on conflict
    |               (docs/09-sync-offline.md §6). Guessing an amount is worse
    |               than asking.
    |   writer    — optional; the default rewrites attributes and nothing else.
    |
    */

    'entities' => [

        'transaction' => [
            'model' => Transaction::class,
            'writer' => TransactionWriter::class,
            'fields' => [
                'type', 'account_id', 'counter_account_id', 'category_id',
                'amount', 'currency', 'fx_rate', 'base_amount', 'base_currency',
                'occurred_at', 'description', 'notes', 'payee', 'reference',
                'tags', 'source',
            ],
            'financial' => [
                'type', 'account_id', 'counter_account_id', 'amount', 'currency',
                'fx_rate', 'base_amount', 'base_currency', 'occurred_at',
            ],
        ],

        'account' => [
            'model' => Account::class,
            'fields' => [
                'name', 'type', 'currency', 'opening_balance', 'iban',
                'card_last4', 'icon', 'color', 'sort_order',
            ],
            'financial' => ['type', 'currency', 'opening_balance'],
        ],

        'category' => [
            'model' => Category::class,
            'fields' => [
                'parent_id', 'name', 'name_key', 'type', 'icon', 'color', 'sort_order',
            ],
            'financial' => ['type', 'parent_id'],
        ],

        'budget' => [
            'model' => Budget::class,
            'fields' => [
                'name', 'scope', 'scope_id', 'period', 'starts_at', 'ends_at',
                'amount', 'currency', 'rollover', 'alert_thresholds',
            ],
            'financial' => [
                'scope', 'scope_id', 'period', 'starts_at', 'ends_at',
                'amount', 'currency',
            ],
        ],

    ],

];
