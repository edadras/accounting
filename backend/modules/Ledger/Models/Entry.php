<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * One side of a double-entry posting.
 *
 * Every transaction produces at least two of these and their base amounts must
 * cancel out. Keeping a real double-entry ledger from day one is what lets the
 * Business and Buildings modules land later without rewriting the core.
 */
final class Entry extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;

    public const DEBIT = 'debit';

    public const CREDIT = 'credit';

    protected $fillable = [
        'workspace_id', 'transaction_id', 'account_id',
        'direction', 'amount', 'currency', 'base_amount', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'base_amount' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isDebit(): bool
    {
        return $this->direction === self::DEBIT;
    }

    /** Debits add to an account, credits take away. */
    public function signedAmount(): int
    {
        return $this->isDebit() ? $this->amount : -$this->amount;
    }

    public function signedBaseAmount(): int
    {
        return $this->isDebit() ? $this->base_amount : -$this->base_amount;
    }
}
