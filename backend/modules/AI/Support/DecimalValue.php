<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use App\Core\Money\Currency;
use InvalidArgumentException;

/**
 * A number lifted out of free text, held as `units / 10^scale`.
 *
 * Everything downstream of parsing is integer arithmetic. "۳۵۰ هزار تومان" goes
 * 350 → ×1000 → ×10 (toman→rial) → 3_500_000 rial without a float ever holding
 * the amount, which is the whole point: the float path is where an order of
 * magnitude quietly disappears.
 */
final readonly class DecimalValue
{
    public function __construct(
        public int $units,
        public int $scale = 0,
    ) {
        if ($scale < 0) {
            throw new InvalidArgumentException('Scale cannot be negative.');
        }
    }

    /** Accepts "350", "1,250.75", "۳۵۰" (already latinised), "12٫5". */
    public static function parse(string $number): self
    {
        $number = str_replace([' ', ',', '_', '٬', "\u{066C}"], '', trim($number));
        $number = str_replace(['٫', "\u{066B}"], '.', $number);

        if (preg_match('/^(-?)(\d*)(?:\.(\d+))?$/', $number, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("[{$number}] is not a number.");
        }

        $whole = $m[2] === '' ? '0' : $m[2];
        $fraction = $m[3] ?? '';
        $sign = $m[1] === '-' ? -1 : 1;

        return new self($sign * (int) ($whole.$fraction), strlen($fraction));
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->units * $factor, $this->scale);
    }

    /**
     * Minor units of $currency, rounding half-up when the text carried more
     * precision than the currency has.
     */
    public function toMinorUnits(Currency $currency): int
    {
        $shift = $currency->minorUnit - $this->scale;

        if ($shift >= 0) {
            return $this->units * (10 ** $shift);
        }

        $divisor = 10 ** -$shift;
        $half = intdiv($divisor, 2);

        return $this->units < 0
            ? -intdiv(-$this->units + $half, $divisor)
            : intdiv($this->units + $half, $divisor);
    }

    public function isZero(): bool
    {
        return $this->units === 0;
    }
}
