<?php

declare(strict_types=1);

namespace Modules\Buildings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Buildings\Support\DecimalWeight;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * One apartment or shop in a building, with the facts a charge formula splits
 * by: floor area, how many people live there, and a manual share factor for
 * buildings whose rules are none of the above.
 */
final class BuildingUnit extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;

    protected $fillable = [
        'building_id', 'workspace_id', 'unit_no', 'area_m2', 'residents_count',
        'owner_name', 'owner_contact', 'tenant_name', 'tenant_contact',
        'share_factor', 'is_occupied',
    ];

    protected function casts(): array
    {
        return [
            // Decimal casts keep these as exact strings; DecimalWeight turns
            // them into integer weights without a float ever being involved.
            'area_m2' => 'decimal:2',
            'share_factor' => 'decimal:4',
            'residents_count' => 'integer',
            'is_occupied' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        $sync = function (self $unit): void {
            $unit->building()->first()?->refreshUnitsCount();
        };

        self::created($sync);
        self::deleted($sync);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(BuildingCharge::class, 'unit_id');
    }

    /** Area in square centimetres — an exact integer suitable as a split weight. */
    public function areaWeight(): int
    {
        return DecimalWeight::scale((string) $this->area_m2, 2);
    }

    /** Share factor scaled to four decimal places, as an exact integer. */
    public function shareWeight(): int
    {
        return DecimalWeight::scale((string) $this->share_factor, 4);
    }
}
