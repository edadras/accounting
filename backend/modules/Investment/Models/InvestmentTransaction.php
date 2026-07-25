<?php

declare(strict_types=1);

namespace Modules\Investment\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Investment\Support\Quantity;
use Modules\Ledger\Models\Transaction;

/**
 * One thing that happened to a position: a purchase, a sale, a dividend, a
 * standalone fee, a share split.
 *
 * These rows are the history; `investments` is only their running total. They
 * are never edited, which is what makes a realised-gains report reproducible.
 */
final class InvestmentTransaction extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;

    public const BUY = 'buy';

    public const SELL = 'sell';

    public const DIVIDEND = 'dividend';

    public const FEE = 'fee';

    public const SPLIT = 'split';

    public const ACTIONS = [self::BUY, self::SELL, self::DIVIDEND, self::FEE, self::SPLIT];

    protected $fillable = [
        'workspace_id', 'investment_id', 'action', 'quantity', 'price', 'fee',
        'currency', 'fx_rate', 'base_amount', 'base_currency', 'realized_profit',
        'occurred_at', 'notes', 'transaction_id', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8',
            'price' => 'integer',
            'fee' => 'integer',
            'fx_rate' => 'string',
            'base_amount' => 'integer',
            'realized_profit' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Investment, $this>
     */
    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    /**
     * The ledger transaction that moved the cash, when there was one.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function quantityUnits(): int
    {
        return Quantity::parse((string) $this->quantity);
    }

    /** Price per unit, not the value of the trade. */
    public function unitPrice(): Money
    {
        return Money::of((int) $this->price, $this->currency);
    }

    public function feeMoney(): Money
    {
        return Money::of((int) $this->fee, $this->currency);
    }

    /** Quantity times unit price, before fees. */
    public function grossMoney(): Money
    {
        return Money::of(
            Quantity::valueOf($this->quantityUnits(), (int) $this->price),
            $this->currency,
        );
    }

    public function baseMoney(): Money
    {
        return Money::of((int) $this->base_amount, $this->base_currency);
    }

    public function realizedProfitMoney(): Money
    {
        return Money::of((int) $this->realized_profit, $this->currency);
    }
}
