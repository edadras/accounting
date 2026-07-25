<?php

declare(strict_types=1);

namespace App\Core\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An exact monetary amount held as an integer number of minor units.
 *
 * Rules this class exists to enforce:
 *   1. No float ever holds an amount.
 *   2. Two different currencies can never be combined implicitly — the caller
 *      must convert and record the rate they used.
 *   3. Conversion rounds half-up on the target currency's smallest unit, and
 *      splitting an amount never loses or invents a unit.
 */
final readonly class Money implements JsonSerializable
{
    public function __construct(
        public int $minorUnits,
        public Currency $currency,
    ) {}

    public static function of(int $minorUnits, string|Currency $currency): self
    {
        return new self(
            $minorUnits,
            $currency instanceof Currency ? $currency : Currency::of($currency),
        );
    }

    public static function zero(string|Currency $currency): self
    {
        return self::of(0, $currency);
    }

    /**
     * Builds from a major-unit decimal string such as "12.34".
     *
     * A string, never a float — "0.1 + 0.2" must not be able to enter the
     * system. More precision than the currency supports is rejected rather
     * than rounded away silently.
     */
    public static function fromDecimalString(string $amount, string|Currency $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);
        $amount = str_replace([' ', ',', '_'], '', trim($amount));

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $amount, $matches)) {
            throw new InvalidArgumentException("[{$amount}] is not a valid decimal amount.");
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = $matches[2] === '' ? '0' : $matches[2];
        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > $currency->minorUnit) {
            throw new InvalidArgumentException(
                "{$currency->code} supports {$currency->minorUnit} decimal places, got [{$amount}]."
            );
        }

        $fraction = str_pad($fraction, $currency->minorUnit, '0');

        return new self($sign * (int) ($whole.$fraction), $currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other, 'add');

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other, 'subtract');

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function multipliedBy(int|float|string $factor): self
    {
        return new self(self::roundHalfUp($this->minorUnits * (float) $factor), $this->currency);
    }

    /**
     * Converts to $target at $rate, where $rate is quoted in major units:
     * "1 unit of this currency buys $rate units of target".
     *
     * The minor-unit scale difference between the two currencies is applied
     * here, which is why converting IRR (0 dp) to USD (2 dp) works.
     */
    public function convertTo(string|Currency $target, float|string $rate): self
    {
        $target = $target instanceof Currency ? $target : Currency::of($target);

        if ($this->currency->equals($target)) {
            return $this;
        }

        $scale = $target->factor() / $this->currency->factor();

        return new self(
            self::roundHalfUp($this->minorUnits * (float) $rate * $scale),
            $target,
        );
    }

    /**
     * Splits into $parts amounts whose sum is exactly this amount.
     *
     * The remainder is distributed one minor unit at a time to the first
     * recipients, so splitting 100 three ways gives 34/33/33 — never 33/33/33
     * with a unit quietly evaporating. Used by Travel split-expense.
     *
     * @return list<self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot split into fewer than one part.');
        }

        $base = intdiv($this->minorUnits, $parts);
        $remainder = $this->minorUnits - ($base * $parts);
        $step = $remainder >= 0 ? 1 : -1;
        $remainder = abs($remainder);

        $slices = [];

        for ($i = 0; $i < $parts; $i++) {
            $extra = $i < $remainder ? $step : 0;
            $slices[] = new self($base + $extra, $this->currency);
        }

        return $slices;
    }

    /**
     * Splits by integer weights, giving any remainder to the largest weights.
     *
     * @param  list<int>  $weights
     * @return list<self>
     */
    public function allocateByWeights(array $weights): array
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            throw new InvalidArgumentException('Split weights must sum to a positive number.');
        }

        $slices = [];
        $assigned = 0;

        foreach ($weights as $weight) {
            $share = intdiv($this->minorUnits * $weight, $total);
            $slices[] = $share;
            $assigned += $share;
        }

        $remainder = $this->minorUnits - $assigned;
        $order = array_keys($weights);
        usort($order, fn (int $a, int $b) => $weights[$b] <=> $weights[$a]);

        $step = $remainder >= 0 ? 1 : -1;

        for ($i = 0; $i < abs($remainder); $i++) {
            $slices[$order[$i % count($order)]] += $step;
        }

        // array_values: the remainder loop writes back through $order, which
        // PHPStan cannot see keeps the keys 0..n-1 intact.
        return array_values(array_map(fn (int $units) => new self($units, $this->currency), $slices));
    }

    /** @param  iterable<self>  $amounts */
    public static function sum(iterable $amounts, string|Currency $currency): self
    {
        $total = self::zero($currency);

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency->equals($other->currency);
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other, 'compare');

        return $this->minorUnits <=> $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /** Major-unit representation as a string, e.g. "12.34". Display only. */
    public function toDecimalString(): string
    {
        $negative = $this->minorUnits < 0;
        $units = abs($this->minorUnits);
        $factor = $this->currency->factor();

        $whole = intdiv($units, $factor);
        $body = (string) $whole;

        if ($this->currency->minorUnit > 0) {
            $fraction = str_pad((string) ($units % $factor), $this->currency->minorUnit, '0', STR_PAD_LEFT);
            $body .= '.'.$fraction;
        }

        return ($negative ? '-' : '').$body;
    }

    /** @return array{amount:int,currency:string,minor_unit:int,decimal:string} */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->minorUnits,
            'currency' => $this->currency->code,
            'minor_unit' => $this->currency->minorUnit,
            'decimal' => $this->toDecimalString(),
        ];
    }

    private static function roundHalfUp(float $value): int
    {
        return $value < 0
            ? -(int) floor(-$value + 0.5)
            : (int) floor($value + 0.5);
    }

    private function assertSameCurrency(self $other, string $operation): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot %s %s and %s. Convert explicitly and record the rate used.',
                $operation,
                $this->currency->code,
                $other->currency->code,
            ));
        }
    }

    public function __toString(): string
    {
        return $this->currency->code.' '.$this->toDecimalString();
    }
}
