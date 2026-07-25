<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Invoice numbering
    |--------------------------------------------------------------------------
    |
    | Tokens available in `format`: {prefix} {year} {month} {sequence}.
    | A workspace may override any of these under settings.invoice_number,
    | because two companies sharing one installation number their invoices
    | differently and neither of them will change to suit the other.
    |
    | `reset` decides when the counter restarts: 'year', 'month' or 'never'.
    |
    */

    'invoice_number' => [
        'format' => '{prefix}-{year}-{sequence}',

        'prefix' => [
            'sale' => 'INV',
            'purchase' => 'BIL',
        ],

        'padding' => 4,

        'reset' => 'year',
    ],

];
