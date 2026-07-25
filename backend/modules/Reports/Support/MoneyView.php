<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use App\Core\Money\Currency;
use App\Core\Money\Money;

/**
 * The single money shape every report response uses.
 *
 * Identical to the `amount` object in TransactionResource: the client formats
 * from `value` + `minor_unit` and never re-derives an amount from `decimal`.
 */
final class MoneyView
{
    /** @return array{value:int,currency:string,minor_unit:int,decimal:string} */
    public static function of(Money $money): array
    {
        return [
            'value' => $money->minorUnits,
            'currency' => $money->currency->code,
            'minor_unit' => $money->currency->minorUnit,
            'decimal' => $money->toDecimalString(),
        ];
    }

    /** @return array{value:int,currency:string,minor_unit:int,decimal:string} */
    public static function ofMinorUnits(int $minorUnits, Currency $currency): array
    {
        return self::of(new Money($minorUnits, $currency));
    }

    /** @return array{value:int,currency:string,minor_unit:int,decimal:string} */
    public static function zero(Currency $currency): array
    {
        return self::of(Money::zero($currency));
    }

    /**
     * Share of a total, as a percentage rounded to two places.
     *
     * A ratio is not money, so a float is correct here — but the money it is
     * derived from stayed integer the whole way.
     */
    public static function percentage(int $part, int $total): float
    {
        return $total === 0 ? 0.0 : round(($part / $total) * 100, 2);
    }
}
