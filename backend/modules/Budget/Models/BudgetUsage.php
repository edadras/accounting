<?php

declare(strict_types=1);

namespace Modules\Budget\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * Materialized spend for one budget in one period.
 *
 * A cache of what CalculateBudgetUsage derives from the ledger: the dashboard
 * reads thousands of these and must not re-scan the transaction table to draw a
 * progress bar. The transactions remain the truth.
 */
final class BudgetUsage extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const CREATED_AT = null;

    protected $fillable = [
        'workspace_id', 'budget_id', 'period_key', 'spent_amount', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'spent_amount' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function money(): Money
    {
        return Money::of($this->spent_amount, $this->currency);
    }
}
