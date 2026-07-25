<?php

declare(strict_types=1);

namespace Modules\Documents\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\Documentable;

/**
 * Gives any model a file drawer: `$transaction->documents`.
 *
 * Attaching goes through Documentable rather than the relation's attach(), so
 * the link row gets its ULID and workspace_id like every other row.
 */
trait HasDocuments
{
    public function documents(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'documentable')
            ->withTimestamps()
            ->latest('documents.created_at');
    }

    public function attachDocument(Document $document): Documentable
    {
        return $document->attachTo($this);
    }

    public function detachDocument(Document $document): void
    {
        $document->detachFrom($this);
    }
}
