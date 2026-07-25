<?php

declare(strict_types=1);

namespace Modules\Business\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * Somebody the business owes money to or is owed money by: a customer, a
 * supplier, an employee.
 */
final class Contact extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;
    use SoftDeletes;

    public const TYPE_CUSTOMER = 'customer';

    public const TYPE_SUPPLIER = 'supplier';

    public const TYPE_EMPLOYEE = 'employee';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_CUSTOMER,
        self::TYPE_SUPPLIER,
        self::TYPE_EMPLOYEE,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'workspace_id', 'type', 'name', 'phone', 'email', 'tax_id', 'address',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
