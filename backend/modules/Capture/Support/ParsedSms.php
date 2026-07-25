<?php

declare(strict_types=1);

namespace Modules\Capture\Support;

use Modules\AI\Support\ExtractedAmount;
use Modules\Ledger\Models\Transaction;

/** What one bank SMS turned out to say. Never a transaction. */
final readonly class ParsedSms
{
    public const DEPOSIT = 'deposit';

    public const WITHDRAWAL = 'withdrawal';

    public function __construct(
        public string $patternKey,
        public string $bank,
        public string $direction,
        public ExtractedAmount $amount,
        public ?string $accountFragment,
        public ?int $balance,
        public string $matched,
    ) {}

    /**
     * Money in is income, money out is an expense.
     *
     * The whole point of reading the direction word is this line. Reversing it
     * does not merely misplace the amount — it moves it to the other side of
     * every total, so the error shows up at twice its size.
     */
    public function type(): string
    {
        return $this->direction === self::DEPOSIT
            ? Transaction::TYPE_INCOME
            : Transaction::TYPE_EXPENSE;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pattern' => $this->patternKey,
            'bank' => $this->bank,
            'direction' => $this->direction,
            'type' => $this->type(),
            'account_fragment' => $this->accountFragment,
            'balance' => $this->balance,
            'matched' => $this->matched,
            'amount' => $this->amount->toArray(),
        ];
    }
}
