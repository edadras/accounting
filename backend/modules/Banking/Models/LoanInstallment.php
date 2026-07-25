<?php

declare(strict_types=1);

namespace Modules\Banking\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Transaction;

final class LoanInstallment extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;

    public const STATUS_DUE = 'due';

    public const STATUS_PAID = 'paid';

    public const STATUS_LATE = 'late';

    public const STATUS_PARTIAL = 'partial';

    public const STATUSES = [self::STATUS_DUE, self::STATUS_PAID, self::STATUS_LATE, self::STATUS_PARTIAL];

    protected $fillable = [
        'workspace_id', 'loan_id', 'number', 'due_date', 'principal_part',
        'interest_part', 'total_amount', 'paid_amount', 'penalty_amount',
        'paid_at', 'status', 'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'due_date' => 'date',
            'principal_part' => 'integer',
            'interest_part' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'penalty_amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** What is still owed, penalty included. */
    public function remaining(): int
    {
        return max(0, $this->total_amount + $this->penalty_amount - $this->paid_amount);
    }

    public function isSettled(): bool
    {
        return $this->remaining() === 0;
    }

    public function totalMoney(string $currency): Money
    {
        return Money::of($this->total_amount, $currency);
    }

    /**
     * How much of what has been paid so far counts against the principal.
     *
     * A part-payment is split across principal and interest in proportion to
     * the instalment's own composition, using the allocator so the two halves
     * add back up to exactly what was paid.
     */
    public function principalPaid(string $currency): Money
    {
        $paid = min($this->paid_amount, $this->total_amount);

        if ($paid <= 0) {
            return Money::zero($currency);
        }

        if ($this->interest_part === 0) {
            return Money::of(min($paid, $this->principal_part), $currency);
        }

        if ($this->principal_part === 0) {
            return Money::zero($currency);
        }

        return Money::of($paid, $currency)
            ->allocateByWeights([$this->principal_part, $this->interest_part])[0];
    }
}
