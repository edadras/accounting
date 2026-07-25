<?php

declare(strict_types=1);

namespace Modules\Billing\Contracts;

use App\Core\Money\Money;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\PaymentResult;

/**
 * The seam a real processor plugs into.
 *
 * Nothing above this interface knows whether money moved through Stripe, a
 * local PSP or a spreadsheet — swapping one in is a binding in the service
 * provider and no change anywhere else.
 */
interface PaymentGateway
{
    public function name(): string;

    /** @param  array<string, mixed>  $meta */
    public function charge(Subscription $subscription, Money $amount, array $meta = []): PaymentResult;

    public function refund(string $reference, Money $amount): PaymentResult;
}
