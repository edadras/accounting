<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Resources;

use App\Core\Money\Money;

/**
 * The one shape money takes on the wire. The client formats it; it never
 * re-derives the amount from a decimal string.
 */
final class MoneyPayload
{
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
