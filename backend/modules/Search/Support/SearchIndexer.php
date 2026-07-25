<?php

declare(strict_types=1);

namespace Modules\Search\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Documents\Models\Document;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Contracts\WritableSearchEngine;
use Modules\Search\Models\SearchEntry;

/**
 * Keeps the normalised copy of every searchable record up to date.
 *
 * Normalising on write rather than on read is not an optimisation, it is the
 * only workable option: docs/06-i18n-rtl.md §7 needs forty-odd substitutions,
 * and expressing those as nested SQL over a column both overflows SQLite's
 * parser and forces a full scan. The table therefore stays the canonical
 * index even when Meilisearch is the engine answering queries: the entry is
 * written here first and then handed on, so a search server that was down
 * during a save can be rebuilt from the database rather than from nothing.
 */
final class SearchIndexer
{
    /**
     * Which models are searchable, under which group, over which columns.
     *
     * @var array<string, array{class-string<Model>, list<string>}>
     */
    private const SOURCES = [
        SearchEngine::TYPE_TRANSACTIONS => [Transaction::class, ['description', 'payee', 'notes']],
        SearchEngine::TYPE_DOCUMENTS => [Document::class, ['original_name', 'ocr_text']],
        SearchEngine::TYPE_CATEGORIES => [Category::class, ['name']],
    ];

    /** @return array<string, array{class-string<Model>, list<string>}> */
    public static function sources(): array
    {
        return self::SOURCES;
    }

    public static function index(Model $model): void
    {
        $type = self::typeFor($model);

        if ($type === null || blank($model->getAttribute('workspace_id'))) {
            return;
        }

        [, $columns] = self::SOURCES[$type];

        $content = TextNormalizer::normalize(implode(' ', array_filter(
            array_map(fn (string $column): ?string => $model->getAttribute($column), $columns),
        )));

        $workspaceId = (string) $model->getAttribute('workspace_id');

        // Written outside the workspace scope so that indexing works from a
        // queue or a console command, where no workspace is active.
        $entry = SearchEntry::query()->withoutWorkspaceScope()->updateOrCreate(
            [
                'indexable_type' => $model->getMorphClass(),
                'indexable_id' => (string) $model->getKey(),
            ],
            [
                'workspace_id' => $workspaceId,
                'type' => $type,
                'content' => $content,
            ],
        );

        if (EmbeddingIndexer::writesOnSave()) {
            EmbeddingIndexer::index($workspaceId, $model->getMorphClass(), (string) $model->getKey(), $content);
        }

        self::engine()?->put($entry);
    }

    public static function forget(Model $model): void
    {
        $type = self::typeFor($model);

        SearchEntry::query()
            ->withoutWorkspaceScope()
            ->where('indexable_type', $model->getMorphClass())
            ->where('indexable_id', (string) $model->getKey())
            ->delete();

        EmbeddingIndexer::forget($model->getMorphClass(), (string) $model->getKey());

        if ($type !== null) {
            self::engine()?->remove($type, (string) $model->getKey());
        }
    }

    /**
     * The bound engine, if it keeps its own copy of the index.
     *
     * Asked by capability rather than by name so that adding an engine is a
     * binding and a class, not an edit here.
     */
    private static function engine(): ?WritableSearchEngine
    {
        $engine = app(SearchEngine::class);

        return $engine instanceof WritableSearchEngine ? $engine : null;
    }

    private static function typeFor(Model $model): ?string
    {
        foreach (self::SOURCES as $type => [$class]) {
            if ($model instanceof $class) {
                return $type;
            }
        }

        return null;
    }
}
