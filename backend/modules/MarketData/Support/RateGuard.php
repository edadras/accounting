<?php

declare(strict_types=1);

namespace Modules\MarketData\Support;

/**
 * The one place that decides whether a fetched number may become a rate.
 *
 * A rate is not a value in a row; it is the multiplier under every
 * multi-currency balance, report and open position in the product. A feed that
 * answers `0`, `null`, `"n/a"` or a decimal point in the wrong place would
 * re-price all of them at once and would do it silently — which is why nothing
 * writes to `exchange_rates` without passing through here first.
 */
final readonly class RateGuard
{
    public const NOT_NUMERIC = 'not_numeric';

    public const NOT_POSITIVE = 'not_positive';

    public const IMPLAUSIBLE_JUMP = 'implausible_jump';

    public function __construct(public float $maxChangeFactor = 10.0) {}

    /**
     * @param  string|null  $previous  the last accepted rate for this pair
     * @return string|null the rejection reason, or null when the value may be stored
     */
    public function reject(mixed $value, ?string $previous = null): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return self::NOT_NUMERIC;
        }

        $raw = trim((string) $value);

        if ($raw === '' || ! is_numeric($raw)) {
            return self::NOT_NUMERIC;
        }

        $rate = (float) $raw;

        if (! is_finite($rate)) {
            return self::NOT_NUMERIC;
        }

        if ($rate <= 0.0) {
            return self::NOT_POSITIVE;
        }

        if ($previous === null) {
            return null;
        }

        $last = (float) $previous;

        // Nothing to compare against: an unusable previous value is not
        // evidence that this one is wrong.
        if ($last <= 0.0 || ! is_finite($last)) {
            return null;
        }

        $factor = max($rate / $last, $last / $rate);

        return $factor > $this->maxChangeFactor ? self::IMPLAUSIBLE_JUMP : null;
    }

    /**
     * A decimal literal for a `decimal(30,12)` column.
     *
     * Values already written in plain decimal are passed through untouched —
     * rounding on the way in is exactly what this module must not do. Only
     * scientific notation is expanded, because several engines refuse it in a
     * decimal column, and the twelve places used are the column's own scale.
     */
    public static function decimalLiteral(string $value): string
    {
        $value = trim($value);

        if (stripos($value, 'e') === false) {
            return $value;
        }

        return rtrim(rtrim(sprintf('%.12F', (float) $value), '0'), '.');
    }
}
