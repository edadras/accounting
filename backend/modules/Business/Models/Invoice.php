<?php

declare(strict_types=1);

namespace Modules\Business\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A sale or purchase invoice.
 *
 * `direction` is what decides whether settling it is income or expense; the
 * amounts are never signed, exactly as in the ledger.
 */
final class Invoice extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;
    use SoftDeletes;

    public const DIRECTION_SALE = 'sale';

    public const DIRECTION_PURCHASE = 'purchase';

    public const DIRECTIONS = [self::DIRECTION_SALE, self::DIRECTION_PURCHASE];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_PARTIAL,
        self::STATUS_PAID,
        self::STATUS_OVERDUE,
        self::STATUS_VOID,
    ];

    protected $fillable = [
        'workspace_id', 'number', 'contact_id', 'project_id', 'direction',
        'issue_date', 'due_date', 'subtotal', 'discount', 'tax', 'total',
        'currency', 'fx_rate', 'base_total', 'base_currency', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'base_total' => 'integer',
            'fx_rate' => 'string',
            'version' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isSale(): bool
    {
        return $this->direction === self::DIRECTION_SALE;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function money(): Money
    {
        return Money::of((int) $this->total, $this->currency);
    }

    public function baseMoney(): Money
    {
        return Money::of((int) $this->base_total, $this->base_currency);
    }

    public function paid(): Money
    {
        return Money::of((int) $this->payments()->sum('amount'), $this->currency);
    }

    public function outstanding(): Money
    {
        return $this->money()->minus($this->paid());
    }

    /**
     * Re-derives the status from the payments actually recorded.
     *
     * The status is a cache over the payments table, the same way an account
     * balance is a cache over its entries — so it is recomputed from them
     * rather than incremented, and cannot drift.
     */
    public function refreshPaymentStatus(): self
    {
        if ($this->isVoid()) {
            return $this;
        }

        $paid = $this->paid();

        $this->status = match (true) {
            $paid->minorUnits >= (int) $this->total => self::STATUS_PAID,
            $paid->isPositive() => self::STATUS_PARTIAL,
            $this->isPastDue() => self::STATUS_OVERDUE,
            default => in_array($this->status, [self::STATUS_PARTIAL, self::STATUS_PAID, self::STATUS_OVERDUE], true)
                ? self::STATUS_SENT
                : $this->status,
        };

        $this->save();

        return $this;
    }

    public function isPastDue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! in_array($this->status, [self::STATUS_PAID, self::STATUS_VOID], true);
    }

    public function scopeOfDirection(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_PAID, self::STATUS_VOID]);
    }

    /** Void invoices never count toward anything a report shows. */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_VOID);
    }
}
