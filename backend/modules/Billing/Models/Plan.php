<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A row in the published catalogue.
 *
 * Global rather than per-workspace, and a projection of Config/plans.php rather
 * than a source of truth: it exists so an invoice can name a price that was
 * really offered, and so the client can render a pricing page without reading
 * config. Entitlements never look here — see Support\Entitlements.
 */
final class Plan extends Model
{
    use HasUlidKey;

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    public const INTERVALS = [self::INTERVAL_MONTHLY, self::INTERVAL_YEARLY];

    protected $fillable = [
        'code', 'name', 'price', 'currency', 'interval', 'features', 'rank', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'features' => 'array',
            'rank' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    public function money(): Money
    {
        return Money::of($this->price, $this->currency);
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }
}
