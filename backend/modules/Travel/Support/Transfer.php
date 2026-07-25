<?php

declare(strict_types=1);

namespace Modules\Travel\Support;

use App\Core\Money\Money;

/**
 * One suggested payment: who pays, who receives, how much. Produced by
 * SettleTrip before anything is written, so a trip can be previewed and only
 * then settled.
 */
final readonly class Transfer
{
    public function __construct(
        public string $fromMemberId,
        public string $toMemberId,
        public Money $amount,
    ) {}
}
