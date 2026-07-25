<?php

declare(strict_types=1);

namespace Modules\Banking\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

/**
 * A cheque: a promise of money on a date, which is not the same thing as money.
 *
 * Nothing is posted to the ledger until it clears — see ClearCheck.
 */
final class Check extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    public const DIRECTION_RECEIVED = 'received';

    public const DIRECTION_ISSUED = 'issued';

    public const DIRECTION_GUARANTEE = 'guarantee';

    public const DIRECTIONS = [
        self::DIRECTION_RECEIVED,
        self::DIRECTION_ISSUED,
        self::DIRECTION_GUARANTEE,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ISSUED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_CLEARED,
        self::STATUS_BOUNCED,
        self::STATUS_VOID,
    ];

    /** A cheque in one of these states will never move money again. */
    public const TERMINAL_STATUSES = [self::STATUS_BOUNCED, self::STATUS_VOID];

    protected $fillable = [
        'workspace_id', 'account_id', 'direction', 'check_number', 'amount',
        'currency', 'base_amount', 'due_date', 'status', 'party_name', 'notes',
        'transaction_id', 'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'base_amount' => 'integer',
            'due_date' => 'date',
            'cleared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isCleared(): bool
    {
        return $this->status === self::STATUS_CLEARED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * The ledger transaction type this cheque produces when it clears.
     *
     * A guarantee cheque only ever clears when it is called in, which always
     * means money leaving — so it posts like an issued one.
     */
    public function ledgerType(): string
    {
        return $this->direction === self::DIRECTION_RECEIVED
            ? Transaction::TYPE_INCOME
            : Transaction::TYPE_EXPENSE;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDueBefore(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->whereDate('due_date', '<=', $date);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
