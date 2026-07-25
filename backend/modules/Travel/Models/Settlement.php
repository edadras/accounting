<?php

declare(strict_types=1);

namespace Modules\Travel\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Transaction;

/**
 * A payment from one member to another that closes part of the debt a trip
 * left behind. Always in the trip's base currency.
 */
final class Settlement extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;

    protected $fillable = [
        'trip_id', 'workspace_id', 'from_member_id', 'to_member_id',
        'amount', 'currency', 'settled_at', 'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function fromMember(): BelongsTo
    {
        return $this->belongsTo(TripMember::class, 'from_member_id');
    }

    public function toMember(): BelongsTo
    {
        return $this->belongsTo(TripMember::class, 'to_member_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
