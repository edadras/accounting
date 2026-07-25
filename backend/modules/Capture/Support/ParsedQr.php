<?php

declare(strict_types=1);

namespace Modules\Capture\Support;

use Carbon\CarbonImmutable;

/** What one QR payload turned out to say. Never a transaction. */
final readonly class ParsedQr
{
    public const FORMAT_RECEIPT_KV = 'receipt_kv';

    public const FORMAT_EMV_TLV = 'emv_tlv';

    /** @param array<string, mixed> $fields */
    public function __construct(
        public string $format,
        public string $type,
        public int $amount,
        public string $currency,
        public bool $currencyExplicit,
        public ?string $merchant,
        public ?string $reference,
        public ?CarbonImmutable $occurredAt,
        public array $fields = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'currency_explicit' => $this->currencyExplicit,
            'merchant' => $this->merchant,
            'reference' => $this->reference,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'fields' => $this->fields,
        ];
    }
}
