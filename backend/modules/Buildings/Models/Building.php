<?php

declare(strict_types=1);

namespace Modules\Buildings\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Account;

/**
 * A building under management: its units, the formula its monthly charge is
 * split by, and the ledger account that holds its fund.
 */
final class Building extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    public const FORMULA_FIXED = 'fixed';

    public const FORMULA_PER_AREA = 'per_area';

    public const FORMULA_PER_RESIDENT = 'per_resident';

    public const FORMULA_MIXED = 'mixed';

    public const FORMULAS = [
        self::FORMULA_FIXED,
        self::FORMULA_PER_AREA,
        self::FORMULA_PER_RESIDENT,
        self::FORMULA_MIXED,
    ];

    protected $fillable = [
        'workspace_id', 'name', 'address', 'units_count',
        'fund_account_id', 'charge_formula',
    ];

    protected function casts(): array
    {
        return [
            'units_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function fundAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'fund_account_id');
    }

    /**
     * @return HasMany<BuildingUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(BuildingUnit::class, 'building_id');
    }

    /**
     * @return HasMany<BuildingCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(BuildingCharge::class, 'building_id');
    }

    /**
     * @return HasMany<BuildingExpense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(BuildingExpense::class, 'building_id');
    }

    public function fundBalance(): ?Money
    {
        return $this->fundAccount?->balance();
    }

    /**
     * Re-derives the cached unit count.
     *
     * `units_count` is a display convenience; the rows are the truth, and no
     * charge calculation ever reads it.
     */
    public function refreshUnitsCount(): void
    {
        $count = $this->units()->count();

        if ($count !== $this->units_count) {
            $this->forceFill(['units_count' => $count])->saveQuietly();
        }
    }
}
