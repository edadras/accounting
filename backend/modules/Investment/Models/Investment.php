<?php

declare(strict_types=1);

namespace Modules\Investment\Models;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Investment\Support\Quantity;

/**
 * An open position: gold, dollars, a listed stock, an ETF, crypto, a flat, a
 * car, a stake in a startup.
 *
 * The row is a running summary of the trades behind it — quantity held and the
 * weighted-average price those units cost. Profit is deliberately split in two:
 * `realized_profit` is banked and cannot change, while unrealised profit is
 * recomputed from today's price every time anyone asks.
 */
final class Investment extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;
    use SoftDeletes;

    public const KINDS = [
        'gold', 'fx', 'stock', 'etf', 'crypto', 'real_estate', 'vehicle', 'startup',
    ];

    protected $fillable = [
        'workspace_id', 'name', 'kind', 'symbol', 'quantity', 'avg_buy_price',
        'currency', 'current_price', 'priced_at', 'realized_profit', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:8',
            'avg_buy_price' => 'integer',
            'current_price' => 'integer',
            'realized_profit' => 'integer',
            'priced_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class);
    }

    public function priceCurrency(): Currency
    {
        return Currency::of($this->currency);
    }

    /** Units held, scaled to the 8 decimal places the column stores. */
    public function quantityUnits(): int
    {
        return Quantity::parse((string) $this->quantity);
    }

    public function avgBuyPrice(): Money
    {
        return Money::of((int) $this->avg_buy_price, $this->priceCurrency());
    }

    public function currentPrice(): ?Money
    {
        return $this->current_price === null
            ? null
            : Money::of((int) $this->current_price, $this->priceCurrency());
    }

    /** What the units still held originally cost. */
    public function costBasis(): Money
    {
        return Money::of(
            Quantity::valueOf($this->quantityUnits(), (int) $this->avg_buy_price),
            $this->priceCurrency(),
        );
    }

    /** What they are worth today; falls back to cost while no price is known. */
    public function currentValue(): Money
    {
        return Money::of(
            Quantity::valueOf($this->quantityUnits(), (int) ($this->current_price ?? $this->avg_buy_price)),
            $this->priceCurrency(),
        );
    }

    /** Profit that exists only on paper — it moves with every price update. */
    public function unrealizedProfit(): Money
    {
        return $this->currentValue()->minus($this->costBasis());
    }

    public function realizedProfit(): Money
    {
        return Money::of((int) $this->realized_profit, $this->priceCurrency());
    }

    public function totalProfit(): Money
    {
        return $this->unrealizedProfit()->plus($this->realizedProfit());
    }

    /**
     * Return on investment as a percentage.
     *
     * Measured against the cost of the units still held, and counting realised
     * profit as well: a position that was half sold at a gain is doing well
     * even if what remains has not moved. A fully closed position has no cost
     * basis left to divide by, so it reports zero rather than infinity.
     */
    public function roi(): float
    {
        $basis = $this->costBasis()->minorUnits;

        if ($basis === 0) {
            return 0.0;
        }

        return round($this->totalProfit()->minorUnits / $basis * 100, 6);
    }

    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }
}
