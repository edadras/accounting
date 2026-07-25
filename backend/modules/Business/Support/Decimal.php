<?php

declare(strict_types=1);

namespace Modules\Business\Support;

use Modules\Business\Exceptions\BusinessException;

/**
 * Exact fixed-point arithmetic for the two decimals an invoice legitimately
 * has: a quantity and a tax rate.
 *
 * `Money::multipliedBy()` multiplies through a float, which is fine for an FX
 * rate but not here: a 9% tax on an amount that lands exactly on a half-unit
 * has to round up every time, and binary floats cannot promise that. So the
 * factor is parsed into a scaled integer and the division is done with intdiv,
 * which makes the result reproducible on every machine.
 */
final class Decimal
{
    /** Four decimal places is enough for 33.3333% and for 0.0001 of a unit. */
    public const SCALE = 4;

    /**
     * Parses a decimal into an integer scaled by 10^$scale.
     *
     * A float argument is normalised at this boundary — JSON has no decimal
     * type, so `2.5` unavoidably arrives as one — and anything with more
     * precision than the scale allows is refused rather than silently rounded.
     */
    public static function toScaled(string|int|float $value, int $scale = self::SCALE): int
    {
        $text = is_float($value)
            ? rtrim(rtrim(sprintf('%.'.$scale.'F', $value), '0'), '.')
            : trim((string) $value);

        if ($text === '' || $text === '-') {
            $text = '0';
        }

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $text, $matches)) {
            throw BusinessException::invalidDecimal((string) $value, $scale);
        }

        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > $scale) {
            throw BusinessException::invalidDecimal((string) $value, $scale);
        }

        $whole = $matches[2] === '' ? '0' : $matches[2];
        $sign = $matches[1] === '-' ? -1 : 1;

        return $sign * (int) ($whole.str_pad($fraction, $scale, '0'));
    }

    /** $minorUnits × $factor, rounded half-up to a whole minor unit. */
    public static function multiply(int $minorUnits, string|int|float $factor, int $scale = self::SCALE): int
    {
        return self::divideHalfUp($minorUnits * self::toScaled($factor, $scale), 10 ** $scale);
    }

    /** $percent per cent of $minorUnits, rounded half-up to a whole minor unit. */
    public static function percentOf(int $minorUnits, string|int|float $percent, int $scale = self::SCALE): int
    {
        return self::divideHalfUp($minorUnits * self::toScaled($percent, $scale), 100 * (10 ** $scale));
    }

    /** Renders a scaled integer back as a plain decimal string. */
    public static function toString(int $scaled, int $scale = self::SCALE): string
    {
        $negative = $scaled < 0;
        $digits = str_pad((string) abs($scaled), $scale + 1, '0', STR_PAD_LEFT);

        $whole = substr($digits, 0, -$scale);
        $fraction = rtrim(substr($digits, -$scale), '0');

        return ($negative ? '-' : '').$whole.($fraction === '' ? '' : '.'.$fraction);
    }

    /** Integer division rounding halves away from zero, without touching a float. */
    private static function divideHalfUp(int $numerator, int $denominator): int
    {
        $magnitude = abs($numerator);
        $quotient = intdiv($magnitude, $denominator);
        $remainder = $magnitude - ($quotient * $denominator);

        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }

        return $numerator < 0 ? -$quotient : $quotient;
    }
}
