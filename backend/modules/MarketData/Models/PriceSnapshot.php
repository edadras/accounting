<?php

declare(strict_types=1);

namespace Modules\MarketData\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Concerns\HasUlidKey;

/**
 * One captured price, kept so a chart has a series rather than a single number.
 *
 * Deliberately not workspace-scoped: the price of gold is not anybody's private
 * data, and duplicating the series per workspace would multiply the same rows
 * by the number of tenants.
 */
final class PriceSnapshot extends Model
{
    use HasUlidKey;

    protected $fillable = ['symbol', 'kind', 'price', 'currency', 'source', 'captured_at'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }
}
