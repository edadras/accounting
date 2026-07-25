<?php

declare(strict_types=1);

namespace Modules\Business\Http\Concerns;

use App\Core\Money\Money;

/**
 * Money always leaves the API as {value, currency, minor_unit, decimal}: the
 * client formats it and never re-derives it (docs/05-api-conventions.md).
 */
trait PresentsMoney
{
    /** @return array{value:int,currency:string,minor_unit:int,decimal:string} */
    protected function presentMoney(Money $money): array
    {
        return [
            'value' => $money->minorUnits,
            'currency' => $money->currency->code,
            'minor_unit' => $money->currency->minorUnit,
            'decimal' => $money->toDecimalString(),
        ];
    }

    /** @return array{value:int,currency:string,minor_unit:int,decimal:string} */
    protected function presentAmount(int $minorUnits, string $currency): array
    {
        return $this->presentMoney(Money::of($minorUnits, $currency));
    }
}
