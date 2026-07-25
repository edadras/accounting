<?php

declare(strict_types=1);

namespace Modules\Sync\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Sync\Models\SyncChange;
use Modules\Sync\Support\Cursor;
use Modules\Sync\Support\EntityPayload;
use Modules\Sync\Support\SyncEntity;
use Modules\Sync\Support\SyncRegistry;

/**
 * Everything in the workspace that has moved since a given moment, across every
 * registered entity, in one totally ordered stream.
 *
 * The stream is ordered by `(updated_at, entity, id)`. Ordering by the timestamp
 * alone would be ambiguous — fifty rows written in the same second share it —
 * and an ambiguous order means a resumed pull either loses rows or repeats them.
 */
final readonly class PullChanges
{
    public function __construct(private SyncRegistry $registry) {}

    /**
     * @return array{changes: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function handle(?CarbonImmutable $since, ?Cursor $cursor, int $limit): array
    {
        $rows = [];

        foreach ($this->registry->all() as $entity) {
            foreach ($this->query($entity, $since, $cursor, $limit)->get() as $record) {
                $rows[] = $this->describe($entity, $record);
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['_sort'], $a['entity'], $a['id']]
            <=> [$b['_sort'], $b['entity'], $b['id']]);

        // Each entity was asked for one row more than the page holds, so any
        // surplus here proves there is at least one more page.
        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        $next = null;

        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = (new Cursor($last['_sort'], $last['entity'], $last['id']))->encode();
        }

        return [
            'changes' => array_map(
                static fn (array $row): array => array_diff_key($row, ['_sort' => null]),
                $page,
            ),
            'next_cursor' => $next,
        ];
    }

    /**
     * @return Builder<Model>
     */
    private function query(SyncEntity $entity, ?CarbonImmutable $since, ?Cursor $cursor, int $limit): Builder
    {
        $query = $entity->query()
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit + 1);

        if ($since !== null) {
            $query->where('updated_at', '>=', $since);
        }

        if ($cursor === null) {
            return $query;
        }

        // Resume strictly after the cursor in `(updated_at, entity, id)` order.
        // The entity part cannot be expressed in SQL across separate tables, so
        // it is folded into the predicate this table gets.
        $side = $entity->key <=> $cursor->entity;

        return $query->where(function (Builder $scoped) use ($cursor, $side): void {
            $scoped->where('updated_at', '>', $cursor->updatedAt);

            if ($side > 0) {
                $scoped->orWhere('updated_at', '=', $cursor->updatedAt);
            } elseif ($side === 0) {
                $scoped->orWhere(fn (Builder $tie) => $tie
                    ->where('updated_at', '=', $cursor->updatedAt)
                    ->where('id', '>', $cursor->id));
            }
        });
    }

    /** @return array<string, mixed> */
    private function describe(SyncEntity $entity, Model $record): array
    {
        $updatedAt = EntityPayload::timestampOf($record, 'updated_at');

        return [
            '_sort' => $updatedAt?->format('Y-m-d H:i:s') ?? '',
            'entity' => $entity->key,
            'id' => (string) $record->getKey(),
            'op' => $this->operationOf($record),
            'version' => EntityPayload::versionOf($record),
            'updated_at' => EntityPayload::wire($updatedAt),
            'payload' => EntityPayload::of($entity, $record),
        ];
    }

    private function operationOf(Model $record): string
    {
        if ($record->getAttribute('deleted_at') !== null) {
            return SyncChange::OP_DELETE;
        }

        $createdAt = EntityPayload::timestampOf($record, 'created_at');
        $updatedAt = EntityPayload::timestampOf($record, 'updated_at');

        // A row is only reported as a create while the two stamps still agree.
        // Either one missing means the model does not keep timestamps at all,
        // and an update is the safe reading — the device merges rather than
        // inserting a second copy of a record it may already hold.
        return $createdAt !== null && $updatedAt !== null && $createdAt == $updatedAt
            ? SyncChange::OP_CREATE
            : SyncChange::OP_UPDATE;
    }
}
