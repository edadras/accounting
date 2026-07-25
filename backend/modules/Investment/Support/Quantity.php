<?php

declare(strict_types=1);

namespace Modules\Investment\Support;

use InvalidArgumentException;

/**
 * A holding size — shares, grams, coins — held as an integer number of
 * hundred-millionths (8 decimal places, matching the column definition).
 *
 * Quantities are the one place in the product where a fractional value is
 * legitimate: 0.00318 BTC is a real position. They still never touch a float,
 * because a position multiplied by a price becomes money, and money that came
 * from a float is money that is wrong.
 */
final class Quantity
{
    public const SCALE = 8;

    public const FACTOR = 100_000_000;

    /** Parses "12.5" / "0.00318" into scaled units. */
    public static function parse(string|int $value): int
    {
        $text = str_replace([' ', ',', '_'], '', trim((string) $value));

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $text, $matches) || ($matches[2] === '' && ($matches[3] ?? '') === '')) {
            throw new InvalidArgumentException("[{$value}] is not a valid quantity.");
        }

        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > self::SCALE) {
            throw new InvalidArgumentException(
                'Quantities support '.self::SCALE." decimal places, got [{$value}]."
            );
        }

        $whole = $matches[2] === '' ? '0' : $matches[2];
        $fraction = str_pad($fraction, self::SCALE, '0');

        return ($matches[1] === '-' ? -1 : 1) * (int) ($whole.$fraction);
    }

    /** Renders scaled units back to the decimal string the column stores. */
    public static function format(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        return $sign.intdiv($units, self::FACTOR)
            .'.'.str_pad((string) ($units % self::FACTOR), self::SCALE, '0', STR_PAD_LEFT);
    }

    /**
     * Money value of $units at $pricePerUnit minor units each.
     *
     * Rounds half-up once, at the end, so a position never accumulates a
     * fraction of a minor unit that nobody can pay.
     */
    public static function valueOf(int $units, int $pricePerUnit): int
    {
        return self::divideRoundHalfUp($units * $pricePerUnit, self::FACTOR);
    }

    /**
     * The weighted-average price of two lots — the whole point of tracking a
     * position rather than a pile of trades.
     */
    public static function weightedAveragePrice(int $unitsA, int $priceA, int $unitsB, int $priceB): int
    {
        $total = $unitsA + $unitsB;

        if ($total === 0) {
            return 0;
        }

        return self::divideRoundHalfUp($unitsA * $priceA + $unitsB * $priceB, $total);
    }

    /** Integer division rounding halves away from zero, without touching a float. */
    public static function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if (abs($remainder) * 2 >= $denominator) {
            $quotient += $numerator < 0 ? -1 : 1;
        }

        return $quotient;
    }
}
