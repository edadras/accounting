<?php

declare(strict_types=1);

namespace Modules\Assets\Models;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * Something owned rather than spent: a house, a plot of land, a car, gold, a
 * watch, a painting, an NFT.
 *
 * `current_value` is what the user believes it is worth today and is theirs to
 * set. Book value is a different number entirely — it comes from the
 * depreciation schedule and is computed, never stored.
 */
final class Asset extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    public const KINDS = ['house', 'land', 'car', 'gold', 'watch', 'art', 'nft', 'other'];

    public const DEPRECIATION_NONE = 'none';

    public const DEPRECIATION_LINEAR = 'linear';

    public const DEPRECIATION_DECLINING = 'declining';

    public const DEPRECIATION_METHODS = [
        self::DEPRECIATION_NONE,
        self::DEPRECIATION_LINEAR,
        self::DEPRECIATION_DECLINING,
    ];

    protected $fillable = [
        'workspace_id', 'name', 'kind', 'purchase_price', 'purchase_date',
        'current_value', 'currency', 'depreciation_method', 'depreciation_rate',
        'useful_life_years', 'salvage_value', 'insurance_provider',
        'insurance_expires_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'integer',
            'current_value' => 'integer',
            'salvage_value' => 'integer',
            'useful_life_years' => 'integer',
            'depreciation_rate' => 'decimal:6',
            'purchase_date' => 'date',
            'insurance_expires_at' => 'date',
            'version' => 'integer',
        ];
    }

    public function priceCurrency(): Currency
    {
        return Currency::of($this->currency);
    }

    public function purchasePrice(): Money
    {
        return Money::of((int) $this->purchase_price, $this->priceCurrency());
    }

    public function salvageValue(): Money
    {
        return Money::of((int) $this->salvage_value, $this->priceCurrency());
    }

    /** What the user says it is worth; falls back to what they paid. */
    public function currentValue(): Money
    {
        return Money::of(
            (int) ($this->current_value ?? $this->purchase_price),
            $this->priceCurrency(),
        );
    }

    /** The amount that may be written off over the asset's life. */
    public function depreciableBase(): Money
    {
        return $this->purchasePrice()->minus($this->salvageValue());
    }

    public function depreciates(): bool
    {
        return $this->depreciation_method !== self::DEPRECIATION_NONE;
    }

    public function insuranceExpired(?\DateTimeInterface $on = null): bool
    {
        if ($this->insurance_expires_at === null) {
            return false;
        }

        return $this->insurance_expires_at->isBefore($on ?? now());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInsuranceExpiringBefore(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->whereNotNull('insurance_expires_at')
            ->where('insurance_expires_at', '<=', $date);
    }
}
