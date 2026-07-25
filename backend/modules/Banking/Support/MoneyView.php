<?php

declare(strict_types=1);

namespace Modules\Banking\Support;

use App\Core\Money\Money;

/**
 * The wire shape for money, shared by every Banking response.
 *
 * Identical to the ledger's: the client formats from these four fields and
 * never re-derives an amount from a decimal string.
 */
final readonly class MoneyView
{
    /** @return array{value:int,currency:string,minor_unit:int,decimal:string} */
    public static function of(int $minorUnits, string $currency): array
    {
        return self::from(Money::of($minorUnits, $currency));
    }

    /** @return array{value:int,currency:string,minor_unit:int,decimal:string} */
    public static function from(Money $money): array
    {
        return [
            'value' => $money->minorUnits,
            'currency' => $money->currency->code,
            'minor_unit' => $money->currency->minorUnit,
            'decimal' => $money->toDecimalString(),
        ];
    }
}
