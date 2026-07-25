<?php

declare(strict_types=1);

namespace Modules\Travel\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * What one member owes for one expense.
 *
 * The shares of an expense always sum to it exactly, in both the currency it
 * was paid in and the trip's base currency.
 */
final class SplitShare extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;

    public const MODE_EQUAL = 'equal';

    public const MODE_PERCENT = 'percent';

    public const MODE_WEIGHT = 'weight';

    public const MODE_EXACT = 'exact';

    public const MODES = [self::MODE_EQUAL, self::MODE_PERCENT, self::MODE_WEIGHT, self::MODE_EXACT];

    protected $fillable = [
        'split_expense_id', 'workspace_id', 'member_id',
        'share_amount', 'base_share_amount', 'mode',
    ];

    protected function casts(): array
    {
        return [
            'share_amount' => 'integer',
            'base_share_amount' => 'integer',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(SplitExpense::class, 'split_expense_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(TripMember::class, 'member_id');
    }

    public function money(): Money
    {
        return Money::of($this->share_amount, $this->expense->currency);
    }

    public function baseMoney(): Money
    {
        return Money::of($this->base_share_amount, $this->expense->trip->base_currency);
    }
}
