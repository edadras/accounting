<?php

declare(strict_types=1);

namespace Modules\Billing\Support;

final readonly class PaymentResult
{
    private function __construct(
        public bool $successful,
        public ?string $reference,
        public string $message,
    ) {}

    public static function succeeded(string $reference): self
    {
        return new self(true, $reference, 'ok');
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
