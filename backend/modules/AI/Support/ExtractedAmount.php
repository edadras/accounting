<?php

declare(strict_types=1);

namespace Modules\AI\Support;

final readonly class ExtractedAmount
{
    public function __construct(
        public int $minorUnits,
        public string $currency,
        public bool $currencyExplicit,
        public string $matched,
        public int $multiplier = 1,
        public ?int $tomanRialFactor = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'amount' => $this->minorUnits,
            'currency' => $this->currency,
            'currency_explicit' => $this->currencyExplicit,
            'matched' => $this->matched,
            'multiplier' => $this->multiplier,
            'toman_rial_factor' => $this->tomanRialFactor,
        ];
    }
}
