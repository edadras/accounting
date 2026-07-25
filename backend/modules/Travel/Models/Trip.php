<?php

declare(strict_types=1);

namespace Modules\Travel\Models;

use App\Core\Money\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A shared journey: the people on it, what was spent, and who ends up paying
 * whom.
 */
final class Trip extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'destination', 'starts_at', 'ends_at', 'base_currency',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(TripMember::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(SplitExpense::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function baseCurrency(): Currency
    {
        return Currency::of($this->base_currency);
    }
}
