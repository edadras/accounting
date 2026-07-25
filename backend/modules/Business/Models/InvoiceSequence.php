<?php

declare(strict_types=1);

namespace Modules\Business\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * The per-workspace counter behind invoice numbering.
 *
 * A row that can be locked, rather than `max(number) + 1`: two requests reading
 * the same maximum at the same time is exactly how duplicate invoice numbers
 * get issued.
 */
final class InvoiceSequence extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;

    protected $fillable = ['workspace_id', 'scope', 'next_value'];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }
}
