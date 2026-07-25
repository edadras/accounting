<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\AI\Support\Vector;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * One record's meaning, as a vector.
 *
 * Read through Eloquent like everything else, so the workspace global scope
 * covers the embeddings exactly as it covers the rows they describe: a
 * similarity search cannot surface a neighbour from another workspace, because
 * the neighbour is not in the result set to begin with.
 *
 * There is no `updated_at`. An embedding is not edited — when the text or the
 * model changes, the vector is replaced wholesale, and `created_at` then says
 * when the row last stood for something true.
 */
final class Embedding extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const UPDATED_AT = null;

    protected $table = 'embeddings';

    protected $fillable = [
        'workspace_id', 'owner_type', 'owner_id', 'vector', 'model',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return list<float> */
    public function vector(): array
    {
        return Vector::decode($this->vector);
    }

    /**
     * Vectors from two models are not comparable, so every read narrows to one
     * of them. Named rather than inlined so that forgetting is conspicuous.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForModel(Builder $query, string $model): Builder
    {
        return $query->where('model', $model);
    }
}
