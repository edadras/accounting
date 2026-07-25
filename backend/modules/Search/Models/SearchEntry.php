<?php

declare(strict_types=1);

namespace Modules\Search\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * One record's searchable text, already normalised.
 *
 * Reading through Eloquent means the workspace global scope applies to the
 * index exactly as it applies to the records behind it.
 */
final class SearchEntry extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    protected $table = 'search_index';

    protected $fillable = [
        'workspace_id', 'type', 'indexable_type', 'indexable_id', 'content',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function indexable(): MorphTo
    {
        return $this->morphTo();
    }
}
