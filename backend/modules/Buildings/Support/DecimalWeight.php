<?php

declare(strict_types=1);

namespace Modules\Buildings\Support;

use InvalidArgumentException;

/**
 * Turns a decimal string such as "73.50" into an exact integer at a fixed
 * scale ("7350" at scale 2).
 *
 * Areas and share factors are not money, but they decide how money is split, so
 * they get the same treatment: parsed as digits, never through a float. A
 * `(int) (73.5 * 100)` in the wrong PHP build is 7349, and that silently
 * mis-bills a flat every month.
 */
final class DecimalWeight
{
    public static function scale(?string $value, int $scale): int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $value, $matches)) {
            throw new InvalidArgumentException("[{$value}] is not a valid decimal weight.");
        }

        $whole = $matches[2] === '' ? '0' : $matches[2];
        $fraction = substr(str_pad($matches[3] ?? '', $scale, '0'), 0, $scale);

        $units = (int) ($whole.$fraction);

        return $matches[1] === '-' ? -$units : $units;
    }
}
