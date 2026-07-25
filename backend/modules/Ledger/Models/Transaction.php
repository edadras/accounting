<?php

declare(strict_types=1);

namespace Modules\Ledger\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Audit\Concerns\Auditable;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Documents\Concerns\HasDocuments;

final class Transaction extends Model
{
    use Auditable;
    use BelongsToWorkspace;
    use HasDocuments;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPES = [self::TYPE_INCOME, self::TYPE_EXPENSE, self::TYPE_TRANSFER];

    public const SOURCES = [
        'manual', 'voice', 'ocr', 'sms', 'email', 'qr', 'import', 'recurring', 'api',
    ];

    protected $fillable = [
        'workspace_id', 'type', 'account_id', 'counter_account_id', 'category_id',
        'amount', 'currency', 'fx_rate', 'base_amount', 'base_currency',
        'occurred_at', 'description', 'notes', 'payee', 'reference', 'tags',
        'source', 'source_meta', 'latitude', 'longitude', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'base_amount' => 'integer',
            'fx_rate' => 'string',
            'occurred_at' => 'datetime',
            'tags' => 'array',
            'source_meta' => 'array',
            'is_reconciled' => 'boolean',
            'version' => 'integer',
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
     * @return BelongsTo<Account, $this>
     */
    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<Entry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function baseMoney(): Money
    {
        return Money::of($this->base_amount, $this->base_currency);
    }

    /** Signed base amount: expenses negative, income positive. */
    public function signedBaseAmount(): int
    {
        return $this->type === self::TYPE_EXPENSE ? -$this->base_amount : $this->base_amount;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInCategorySubtree(Builder $query, Category $category): Builder
    {
        return $query->whereIn('category_id', $category->descendantIds());
    }
}
