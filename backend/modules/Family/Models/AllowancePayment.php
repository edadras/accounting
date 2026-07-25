<?php

declare(strict_types=1);

namespace Modules\Family\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Transaction;

/**
 * The record that a member's allowance for one month has been paid, and the
 * ledger transfer that actually moved the money.
 */
final class AllowancePayment extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;

    protected $fillable = [
        'workspace_id', 'member_id', 'payer_member_id', 'period',
        'amount', 'currency', 'transaction_id', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'member_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class, 'payer_member_id');
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
