<?php

declare(strict_types=1);

namespace Modules\DataOps\Support;

use App\Core\Money\Currency;
use App\Core\Money\Money;

/**
 * What a workspace export contains, and which of its columns are money.
 *
 * Amounts live in the database as integer minor units. A spreadsheet showing
 * 35000 where the user spent ₺350.00 is worse than no export at all, so every
 * column named here is written as a decimal string instead.
 */
final class ExportSchema
{
    /** Written as one CSV each, in this order, when the table exists. */
    public const TABLES = ['accounts', 'categories', 'transactions', 'budgets', 'invoices'];

    /**
     * table => [amount column => the column naming its currency]
     *
     * @var array<string, array<string, string>>
     */
    public const MONEY = [
        'accounts' => [
            'opening_balance' => 'currency',
            'current_balance' => 'currency',
        ],
        'categories' => [],
        'transactions' => [
            'amount' => 'currency',
            'base_amount' => 'base_currency',
        ],
        'budgets' => [
            'amount' => 'currency',
        ],
        'invoices' => [
            'subtotal' => 'currency',
            'discount' => 'currency',
            'tax' => 'currency',
            'total' => 'currency',
            'base_total' => 'base_currency',
        ],
    ];

    /**
     * Rewrites the money columns of one row into decimal strings.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function present(string $table, array $row, string $fallbackCurrency): array
    {
        foreach (self::MONEY[$table] ?? [] as $column => $currencyColumn) {
            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $code = is_string($row[$currencyColumn] ?? null) && $row[$currencyColumn] !== ''
                ? $row[$currencyColumn]
                : $fallbackCurrency;

            // An amount in a currency the catalogue does not know cannot be
            // scaled, and guessing the decimal place would be a lie. It goes
            // out as the integer it is, which the header still labels.
            if (! Currency::isSupported($code)) {
                continue;
            }

            $row[$column] = Money::of((int) $row[$column], $code)->toDecimalString();
        }

        return $row;
    }
}
