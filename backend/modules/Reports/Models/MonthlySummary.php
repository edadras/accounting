<?php

declare(strict_types=1);

namespace Modules\Reports\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;

/**
 * A pre-aggregated month, so an annual report is twelve rows instead of fifty
 * thousand.
 *
 * Purely a cache. Transactions are the truth; anything in here can be thrown
 * away and rebuilt, and no report may return an answer it could not also
 * compute from the ledger.
 */
final class MonthlySummary extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;

    public const CREATED_AT = null;

    protected $table = 'monthly_summaries';

    protected $fillable = [
        'workspace_id', 'period_key', 'category_id', 'account_id',
        'income_amount', 'expense_amount', 'currency', 'transaction_count',
    ];

    protected function casts(): array
    {
        return [
            'income_amount' => 'integer',
            'expense_amount' => 'integer',
            'transaction_count' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function income(): Money
    {
        return Money::of($this->income_amount, $this->currency);
    }

    public function expense(): Money
    {
        return Money::of($this->expense_amount, $this->currency);
    }

    public function net(): Money
    {
        return $this->income()->minus($this->expense());
    }

    /**
     * The workspace-wide row for a month: no category, no account.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWorkspaceGrain(Builder $query): Builder
    {
        return $query->whereNull('category_id')->whereNull('account_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForPeriod(Builder $query, string $periodKey): Builder
    {
        return $query->where('period_key', $periodKey);
    }
}
