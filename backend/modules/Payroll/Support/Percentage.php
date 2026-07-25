<?php

declare(strict_types=1);

namespace Modules\Payroll\Support;

use App\Core\Money\Money;
use Modules\Payroll\Exceptions\PayrollException;

/**
 * A payroll rate, held as an exact integer.
 *
 * A tax rate is a number a legislator wrote down, not a measurement, so it is
 * parsed from its decimal text into hundred-thousandths of a unit and never
 * touches a float. `applyTo()` is then integer arithmetic rounded half-up, so
 * "7.5% of 1234567" gives the same minor unit on every machine and in every
 * order of operations.
 */
final readonly class Percentage
{
    /** Decimal places of *percent* that survive parsing: 12.3456% is exact. */
    public const SCALE = 4;

    /** Divisor turning `scaled × amount` back into an amount: 100 × 10^SCALE. */
    private const DIVISOR = 1000000;

    private function __construct(public int $scaled) {}

    public static function of(int|float|string $value): self
    {
        $raw = trim(is_float($value) ? self::floatToDecimalString($value) : (string) $value);

        if ($raw === '') {
            $raw = '0';
        }

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $raw, $matches) || $matches[2] === '' && ($matches[3] ?? '') === '') {
            throw PayrollException::invalidRate((string) $value);
        }

        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > self::SCALE) {
            throw PayrollException::invalidRate((string) $value);
        }

        $whole = $matches[2] === '' ? '0' : $matches[2];
        $sign = $matches[1] === '-' ? -1 : 1;

        return new self($sign * (int) ($whole.str_pad($fraction, self::SCALE, '0')));
    }

    /** Rejects a rate below zero — a negative deduction is an earning in disguise. */
    public static function nonNegative(int|float|string $value): self
    {
        $rate = self::of($value);

        if ($rate->scaled < 0) {
            throw PayrollException::negativeRate((string) $value);
        }

        return $rate;
    }

    public function isZero(): bool
    {
        return $this->scaled === 0;
    }

    /**
     * This percentage of $money, rounded half-up on the smallest unit.
     *
     * Integer throughout: the product is exact and only the final division
     * rounds, so applying 7.5% to a hundred payslips and applying it once to
     * their sum differ by at most the roundings that genuinely occurred.
     */
    public function applyTo(Money $money): Money
    {
        $product = $money->minorUnits * $this->scaled;
        $half = intdiv(self::DIVISOR, 2);

        $units = $product < 0
            ? -intdiv(-$product + $half, self::DIVISOR)
            : intdiv($product + $half, self::DIVISOR);

        return new Money($units, $money->currency);
    }

    /** e.g. "7.5" — display and storage, never re-parsed into arithmetic. */
    public function toDecimalString(): string
    {
        $negative = $this->scaled < 0;
        $units = abs($this->scaled);
        $factor = 10 ** self::SCALE;

        $body = (string) intdiv($units, $factor);
        $fraction = rtrim(str_pad((string) ($units % $factor), self::SCALE, '0', STR_PAD_LEFT), '0');

        if ($fraction !== '') {
            $body .= '.'.$fraction;
        }

        return ($negative ? '-' : '').$body;
    }

    /**
     * JSON has no decimal type, so a rate typed as 7.5 in a request body
     * unavoidably arrives as a float; it is normalised once, here, and is an
     * integer everywhere after.
     */
    private static function floatToDecimalString(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.'.self::SCALE.'F', $value), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    public function __toString(): string
    {
        return $this->toDecimalString().'%';
    }
}
