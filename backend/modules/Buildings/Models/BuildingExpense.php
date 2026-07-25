<?php

declare(strict_types=1);

namespace Modules\Buildings\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;

/**
 * Money the building spent — the lift service call, the cleaner, the water
 * bill — paid out of the fund.
 */
final class BuildingExpense extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;

    protected $fillable = [
        'building_id', 'workspace_id', 'category_id', 'amount', 'currency',
        'occurred_at', 'description', 'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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
}
