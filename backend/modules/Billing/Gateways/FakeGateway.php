<?php

declare(strict_types=1);

namespace Modules\Billing\Gateways;

use App\Core\Money\Money;
use Illuminate\Support\Str;
use Modules\Billing\Contracts\PaymentGateway;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\PaymentResult;

/**
 * The gateway used in tests and local development.
 *
 * It records every charge so a test can assert what would have been taken, and
 * can be told to decline so the failure path is exercised without anyone
 * needing a sandbox account or a card number.
 */
final class FakeGateway implements PaymentGateway
{
    /** @var list<array{subscription_id:string,amount:int,currency:string,meta:array<string,mixed>}> */
    private array $charges = [];

    private ?string $declineReason = null;

    public function name(): string
    {
        return 'fake';
    }

    public function declineWith(string $reason): void
    {
        $this->declineReason = $reason;
    }

    public function accept(): void
    {
        $this->declineReason = null;
    }

    /** @return list<array{subscription_id:string,amount:int,currency:string,meta:array<string,mixed>}> */
    public function charges(): array
    {
        return $this->charges;
    }

    public function charge(Subscription $subscription, Money $amount, array $meta = []): PaymentResult
    {
        if ($this->declineReason !== null) {
            return PaymentResult::failed($this->declineReason);
        }

        $this->charges[] = [
            'subscription_id' => $subscription->id,
            'amount' => $amount->minorUnits,
            'currency' => $amount->currency->code,
            'meta' => $meta,
        ];

        return PaymentResult::succeeded('fake_'.Str::lower((string) Str::ulid()));
    }

    public function refund(string $reference, Money $amount): PaymentResult
    {
        return $this->declineReason !== null
            ? PaymentResult::failed($this->declineReason)
            : PaymentResult::succeeded($reference.'_refund');
    }
}
