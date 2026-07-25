<?php

declare(strict_types=1);

namespace Modules\Travel\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Category;
use Modules\Travel\Exceptions\TravelException;

/**
 * One thing somebody paid for on a trip, together with the shares that say who
 * it was really for.
 */
final class SplitExpense extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    protected $fillable = [
        'trip_id', 'workspace_id', 'payer_member_id', 'amount', 'currency',
        'fx_rate', 'base_amount', 'category_id', 'occurred_at', 'description',
        'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'base_amount' => 'integer',
            'fx_rate' => 'string',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<TripMember, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(TripMember::class, 'payer_member_id');
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<SplitShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(SplitShare::class);
    }

    /**
     * The trip this expense belongs to.
     *
     * Everything that values an expense needs the trip's base currency, and a
     * missing trip has to stop there: carried further it becomes an empty
     * currency code inside a Money and a total nobody can explain.
     */
    public function requireTrip(): Trip
    {
        return $this->trip ?? throw TravelException::tripNotFound((string) $this->trip_id);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function baseMoney(): Money
    {
        return Money::of($this->base_amount, $this->requireTrip()->base_currency);
    }
}
