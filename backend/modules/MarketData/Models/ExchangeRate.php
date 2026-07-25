<?php

declare(strict_types=1);

namespace Modules\MarketData\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A row of the `exchange_rates` table Core creates and ExchangeRateResolver
 * reads. Append-only: a rate that was true at a moment stays on the record, so
 * a report run last month can still be explained.
 */
final class ExchangeRate extends Model
{
    use HasUlidKey;

    protected $table = 'exchange_rates';

    protected $fillable = ['base_code', 'quote_code', 'rate', 'source', 'rated_at'];

    protected function casts(): array
    {
        return [
            'rated_at' => 'datetime',
        ];
    }

    public function scopeForPair(Builder $query, string $base, string $quote): Builder
    {
        return $query->where('base_code', $base)->where('quote_code', $quote);
    }
}
