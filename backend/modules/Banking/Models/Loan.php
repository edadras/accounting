<?php

declare(strict_types=1);

namespace Modules\Banking\Models;

use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Account;

final class Loan extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;

    public const INTEREST_SIMPLE = 'simple';

    public const INTEREST_COMPOUND = 'compound';

    public const INTEREST_TYPES = [self::INTEREST_SIMPLE, self::INTEREST_COMPOUND];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_DEFAULTED = 'defaulted';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_CLOSED, self::STATUS_DEFAULTED];

    /** Instalments fall due once a month, so a year is twelve periods. */
    public const PERIODS_PER_YEAR = 12;

    protected $fillable = [
        'workspace_id', 'bank_id', 'account_id', 'title', 'principal', 'currency',
        'interest_rate', 'interest_type', 'installments_count', 'start_date',
        'penalty_rate', 'outstanding_balance', 'status',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'integer',
            'outstanding_balance' => 'integer',
            'installments_count' => 'integer',
            'interest_rate' => 'string',
            'penalty_rate' => 'string',
            'start_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<LoanInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('number');
    }

    public function principalMoney(): Money
    {
        return Money::of($this->principal, $this->currency);
    }

    public function outstandingMoney(): Money
    {
        return Money::of($this->outstanding_balance, $this->currency);
    }

    /** The rate for one instalment period, as a fraction (0.015 for 18% a year). */
    public function periodicRate(): float
    {
        return ((float) $this->interest_rate) / 100 / self::PERIODS_PER_YEAR;
    }
}
