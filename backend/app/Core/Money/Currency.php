<?php

declare(strict_types=1);

namespace App\Core\Money;

use InvalidArgumentException;

/**
 * A currency the ledger can hold.
 *
 * `minorUnit` is the number of decimal places: 2 for USD, 0 for IRR, 8 for BTC.
 * This is the single fact that lets an integer amount be interpreted correctly,
 * so it travels with every Money instance.
 */
final readonly class Currency
{
    private const CATALOG = [
        'IRR' => ['ریال', 0, false, 'fiat'],
        'IRT' => ['تومان', 0, false, 'fiat'],
        'TRY' => ['₺', 2, true, 'fiat'],
        'USD' => ['$', 2, true, 'fiat'],
        'EUR' => ['€', 2, true, 'fiat'],
        'AED' => ['د.إ', 2, false, 'fiat'],
        'BTC' => ['₿', 8, true, 'crypto'],
        'ETH' => ['Ξ', 8, true, 'crypto'],
        'USDT' => ['₮', 2, true, 'crypto'],
        'XAU' => ['طلا', 4, false, 'metal'],
    ];

    private function __construct(
        public string $code,
        public string $symbol,
        public int $minorUnit,
        public bool $symbolLeading,
        public string $type,
    ) {}

    public static function of(string $code): self
    {
        $code = strtoupper(trim($code));

        if (! isset(self::CATALOG[$code])) {
            throw new InvalidArgumentException("Unknown currency [{$code}].");
        }

        [$symbol, $minorUnit, $symbolLeading, $type] = self::CATALOG[$code];

        return new self($code, $symbol, $minorUnit, $symbolLeading, $type);
    }

    public static function isSupported(string $code): bool
    {
        return isset(self::CATALOG[strtoupper(trim($code))]);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CATALOG);
    }

    /** @return list<array{code:string,symbol:string,minor_unit:int,symbol_leading:bool,type:string}> */
    public static function catalog(): array
    {
        $rows = [];

        foreach (self::CATALOG as $code => [$symbol, $minorUnit, $symbolLeading, $type]) {
            $rows[] = [
                'code' => $code,
                'symbol' => $symbol,
                'minor_unit' => $minorUnit,
                'symbol_leading' => $symbolLeading,
                'type' => $type,
            ];
        }

        return $rows;
    }

    /** 10 ** minorUnit — the divisor between minor and major units. */
    public function factor(): int
    {
        return 10 ** $this->minorUnit;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
