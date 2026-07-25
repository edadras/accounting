<?php

declare(strict_types=1);

namespace Modules\Search\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Contracts\EmbeddingProvider;
use Modules\AI\Models\Embedding;
use Modules\AI\Support\Vector;

/**
 * Keeps the vector beside the normalised text.
 *
 * It embeds the *indexed* content rather than the record's raw columns, so
 * semantic search sees exactly the string keyword search sees: one
 * normalisation, one source of truth about what a record says.
 *
 * Whether this runs on save depends on the provider. The local provider is
 * arithmetic over a few dozen tokens and costs less than the insert it rides
 * along with, so it runs inline and the index is never stale. A remote
 * provider is a network round trip that has no business inside a user's save,
 * so it does not — `search:embed`, or a queued job, catches those up. Either
 * way the read path is identical, which is why the choice can be a config key.
 */
final class EmbeddingIndexer
{
    /**
     * Whether the embeddings table exists.
     *
     * Memoised because this is on the write path of every indexed model, and
     * the answer cannot change within a process.
     */
    private static ?bool $tableExists = null;

    public static function writesOnSave(): bool
    {
        return config('ai.embeddings.driver', 'local') === 'local';
    }

    /**
     * @param  string  $content  already normalised by SearchIndexer
     */
    public static function index(string $workspaceId, string $ownerType, string $ownerId, string $content): void
    {
        if ($workspaceId === '' || ! self::available()) {
            return;
        }

        $provider = app(EmbeddingProvider::class);

        self::store($workspaceId, $ownerType, $ownerId, $provider->name(), $provider->embed($content));
    }

    /**
     * Writes a vector that has already been computed — the shape a batched
     * backfill needs, where one provider call covers hundreds of rows.
     *
     * @param  list<float>  $vector
     */
    public static function store(string $workspaceId, string $ownerType, string $ownerId, string $model, array $vector): void
    {
        if ($workspaceId === '' || $vector === [] || ! self::available()) {
            return;
        }

        // Written outside the workspace scope for the same reason the search
        // index is: backfills and queue workers have no active workspace.
        Embedding::query()->withoutWorkspaceScope()->updateOrCreate(
            [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'model' => $model,
            ],
            [
                'workspace_id' => $workspaceId,
                'vector' => Vector::encode($vector),
                'created_at' => CarbonImmutable::now(),
            ],
        );
    }

    public static function forget(string $ownerType, string $ownerId): void
    {
        if (! self::available()) {
            return;
        }

        Embedding::query()
            ->withoutWorkspaceScope()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->delete();
    }

    private static function available(): bool
    {
        return self::$tableExists ??= Schema::hasTable('embeddings');
    }
}
