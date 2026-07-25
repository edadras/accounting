<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Concerns;

use App\Core\Money\Money;

/**
 * The one money shape the API speaks (docs/05-api-conventions.md): the client
 * formats from `value` + `minor_unit` and never re-derives an amount.
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
}
