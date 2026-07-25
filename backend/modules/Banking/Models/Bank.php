<?php

declare(strict_types=1);

namespace Modules\Banking\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/** A bank the workspace holds accounts, cheques or loans with. */
final class Bank extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;

    protected $fillable = [
        'workspace_id', 'name', 'branch', 'swift', 'country', 'logo',
    ];

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
