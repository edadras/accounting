<?php

declare(strict_types=1);

namespace Modules\Business\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

/**
 * Money actually received against a sale invoice, or actually paid against a
 * purchase invoice, together with the ledger posting it produced.
 */
final class Payment extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;
    use SoftDeletes;

    public const METHODS = ['cash', 'bank', 'card', 'cheque', 'online', 'other'];

    protected $fillable = [
        'workspace_id', 'invoice_id', 'contact_id', 'account_id', 'amount',
        'currency', 'paid_at', 'method', 'transaction_id', 'reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function money(): Money
    {
        return Money::of((int) $this->amount, $this->currency);
    }
}
