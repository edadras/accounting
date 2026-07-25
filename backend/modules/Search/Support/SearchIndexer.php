<?php

declare(strict_types=1);

namespace Modules\Search\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Documents\Models\Document;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Models\SearchEntry;

/**
 * Keeps the normalised copy of every searchable record up to date.
 *
 * Normalising on write rather than on read is not an optimisation, it is the
 * only workable option: docs/06-i18n-rtl.md §7 needs forty-odd substitutions,
 * and expressing those as nested SQL over a column both overflows SQLite's
 * parser and forces a full scan. Meilisearch will take this job over; until
 * then the index is a table.
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

        // Written outside the workspace scope so that indexing works from a
        // queue or a console command, where no workspace is active.
        SearchEntry::query()->withoutWorkspaceScope()->updateOrCreate(
            [
                'indexable_type' => $model->getMorphClass(),
                'indexable_id' => (string) $model->getKey(),
            ],
            [
                'workspace_id' => (string) $model->getAttribute('workspace_id'),
                'type' => $type,
                'content' => $content,
            ],
        );
    }

    public static function forget(Model $model): void
    {
        SearchEntry::query()
            ->withoutWorkspaceScope()
            ->where('indexable_type', $model->getMorphClass())
            ->where('indexable_id', (string) $model->getKey())
            ->delete();
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
