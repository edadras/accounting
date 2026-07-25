<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

/**
 * One column of an exported table, and what kind of value it holds.
 *
 * The type is what lets each format do the right thing with the same cell: a
 * money cell becomes a decimal string in CSV and a real number with a currency
 * format in XLSX, and neither exporter has to know which report it came from.
 */
final readonly class Column
{
    public const TEXT = 'text';

    public const DATE = 'date';

    public const MONEY = 'money';

    public const COUNT = 'count';

    public const PERCENT = 'percent';

    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TEXT,
    ) {}

    public function isNumeric(): bool
    {
        return $this->type === self::MONEY
            || $this->type === self::COUNT
            || $this->type === self::PERCENT;
    }
}
