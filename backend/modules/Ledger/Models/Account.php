<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * Somewhere money sits: a wallet, a bank account, a card, a petty cash box, a
 * crypto holding.
 */
final class Account extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;
    use SoftDeletes;

    public const TYPES = [
        'cash', 'bank', 'card', 'wallet', 'fund',
        'petty_cash', 'crypto', 'gold', 'fx',
    ];

    protected $fillable = [
        'workspace_id', 'name', 'type', 'currency', 'opening_balance',
        'current_balance', 'iban', 'card_last4', 'icon', 'color', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'current_balance' => 'integer',
            'archived_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function balance(): Money
    {
        return Money::of($this->current_balance, $this->currency);
    }

    /**
     * Recomputes the cached balance from the entries that back it.
     *
     * `current_balance` is a cache; the entries are the truth. A daily job
     * calls this to prove they still agree.
     */
    public function recalculateBalance(): Money
    {
        $sum = (int) $this->entries()
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END), 0) AS total")
            ->value('total');

        $balance = $this->opening_balance + $sum;

        if ($balance !== $this->current_balance) {
            $this->forceFill(['current_balance' => $balance])->saveQuietly();
        }

        return Money::of($balance, $this->currency);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
