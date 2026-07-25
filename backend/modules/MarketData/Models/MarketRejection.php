<?php

declare(strict_types=1);

namespace Modules\MarketData\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A number a feed offered and this module refused.
 *
 * Kept because "the rate did not move today" and "the rate we were sent was
 * nonsense and we threw it away" look identical in the rates table, and only
 * one of them is a reason to go and look at the feed.
 */
final class MarketRejection extends Model
{
    use HasUlidKey;

    public const SCOPE_RATE = 'rate';

    public const SCOPE_PRICE = 'price';

    protected $fillable = ['provider', 'scope', 'subject', 'value', 'reason', 'rejected_at'];

    protected function casts(): array
    {
        return [
            'rejected_at' => 'datetime',
        ];
    }
}
