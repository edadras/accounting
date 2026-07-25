<?php

declare(strict_types=1);

namespace Modules\Buildings\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Transaction;

/**
 * One unit's bill for one month.
 */
final class BuildingCharge extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUSES = [self::STATUS_UNPAID, self::STATUS_PARTIAL, self::STATUS_PAID];

    /** The statuses that make a unit a debtor. */
    public const OUTSTANDING_STATUSES = [self::STATUS_UNPAID, self::STATUS_PARTIAL];

    protected $fillable = [
        'building_id', 'unit_id', 'workspace_id', 'period', 'amount', 'currency',
        'due_date', 'status', 'paid_amount', 'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'due_date' => 'date',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BuildingUnit::class, 'unit_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function paidMoney(): Money
    {
        return Money::of($this->paid_amount, $this->currency);
    }

    public function remainingMoney(): Money
    {
        return $this->money()->minus($this->paidMoney());
    }

    public function isSettled(): bool
    {
        return $this->paid_amount >= $this->amount;
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', self::OUTSTANDING_STATUSES);
    }

    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return $query->where('period', $period);
    }
}
