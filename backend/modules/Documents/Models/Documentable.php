<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * The link between one document and one record.
 *
 * It is a first-class model rather than a bare pivot because rows carry a ULID
 * and a workspace_id, and Eloquent's attach() writes pivots with a raw insert
 * that would bypass both. Links are therefore always created through this model.
 */
final class Documentable extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    protected $table = 'documentables';

    protected $fillable = [
        'workspace_id', 'document_id', 'documentable_type', 'documentable_id',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
