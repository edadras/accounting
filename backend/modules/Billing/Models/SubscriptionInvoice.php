<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

final class SubscriptionInvoice extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
    ];

    protected $fillable = [
        'workspace_id', 'subscription_id', 'number', 'plan_code', 'amount',
        'currency', 'status', 'period_start', 'period_end', 'issued_at',
        'paid_at', 'gateway', 'gateway_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
