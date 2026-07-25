<?php

declare(strict_types=1);

namespace Modules\Sync\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Sync\Exceptions\SyncException;
use Modules\Sync\Models\Device;
use Modules\Sync\Models\SyncChange;
use Modules\Sync\Support\EntityPayload;
use Modules\Sync\Support\PayloadDiff;
use Modules\Sync\Support\SyncEntity;
use Modules\Sync\Support\SyncRegistry;

/**
 * Applies a batch of offline changes and returns a verdict for each.
 *
 * Two rules shape everything here.
 *
 * The first is that replaying a batch must be free. The device cannot tell a
 * dropped response from a dropped request, so it retries, and a retry that
 * created a second transaction would be a financial error the user would find
 * months later. Every change is looked up in the log first and answered from it
 * if it has been seen.
 *
 * The second is that a disagreement about money is never settled by the server.
 * Amount, currency, account, date and type come back to the user untouched
 * (docs/09-sync-offline.md §6); guessing the right amount is worse than asking.
 */
final readonly class PushChanges
{
    public function __construct(private SyncRegistry $registry) {}

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return list<array<string, mixed>>
     */
    public function handle(array $changes, ?Device $device = null): array
    {
        $results = [];

        // Sequential and in the order given: the outbox is FIFO per entity, so
        // a create must land before the update that follows it.
        foreach ($changes as $change) {
            $results[] = $this->applyOne($change, $device);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private function applyOne(array $change, ?Device $device): array
    {
        $entity = $this->registry->resolve((string) ($change['entity'] ?? ''));
        $id = (string) ($change['id'] ?? '');
        $operation = (string) ($change['op'] ?? SyncChange::OP_UPDATE);
        $baseVersion = (int) ($change['base_version'] ?? 0);
        $payload = (array) ($change['payload'] ?? []);

        $replay = $this->findReplay($entity, $id, $operation, $baseVersion, $payload);

        if ($replay !== null) {
            return $this->verdictFor($entity, $id, $operation, $replay);
        }

        try {
            $log = DB::transaction(fn (): SyncChange => $this->decide(
                $entity, $id, $operation, $baseVersion, $payload, $device,
            ));
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            // One bad change does not invalidate the rest of the batch
            // (docs/05-api-conventions.md §8).
            $log = $this->log(
                $entity, $id, $operation, $baseVersion, $payload, $device,
                SyncChange::REJECTED, null, $this->codeOf($e),
            );

            return $this->verdictFor($entity, $id, $operation, $log) + ['message' => $e->getMessage()];
        }

        return $this->verdictFor($entity, $id, $operation, $log);
    }

    /** @param  array<string, mixed>  $payload */
    private function decide(
        SyncEntity $entity,
        string $id,
        string $operation,
        int $baseVersion,
        array $payload,
        ?Device $device,
    ): SyncChange {
        $record = $entity->query()->find($id);

        $write = fn (string $status, ?int $version, ?string $reason = null): SyncChange => $this->log(
            $entity, $id, $operation, $baseVersion, $payload, $device, $status, $version, $reason,
        );

        if ($record === null) {
            return match ($operation) {
                // Already gone, or never arrived: either way the device's wish
                // is the state of the world.
                SyncChange::OP_DELETE => $write(SyncChange::APPLIED, $baseVersion),
                SyncChange::OP_CREATE => $write(
                    SyncChange::APPLIED,
                    EntityPayload::versionOf($entity->writerInstance()->create($entity, $id, $payload, 1)),
                ),
                default => $write(SyncChange::CONFLICT, 0, 'entity_missing'),
            };
        }

        $serverVersion = EntityPayload::versionOf($record);
        $trashed = $entity->isSoftDeletable() && $record->getAttribute('deleted_at') !== null;

        if ($trashed) {
            // Deleted here, edited there. The delete does not silently win; the
            // user is shown both and chooses.
            return $operation === SyncChange::OP_DELETE
                ? $write(SyncChange::APPLIED, $serverVersion)
                : $write(SyncChange::CONFLICT, $serverVersion, 'deleted_on_server');
        }

        $changed = PayloadDiff::changedFields($entity, $record, $payload);

        if ($baseVersion === $serverVersion) {
            return $this->applyCleanly($entity, $record, $operation, $payload, $changed, $serverVersion, $write);
        }

        // From here the device was working from a version the server has since
        // moved past.
        if ($operation === SyncChange::OP_DELETE) {
            return $write(SyncChange::CONFLICT, $serverVersion, 'delete_vs_edit');
        }

        if ($changed === []) {
            // Behind, but asking for exactly what is already stored.
            return $write(SyncChange::APPLIED, $serverVersion);
        }

        $financial = PayloadDiff::financial($entity, $changed);

        if ($financial !== []) {
            return $write(SyncChange::CONFLICT, $serverVersion, 'financial_conflict');
        }

        return $this->merge($entity, $record, $payload, $changed, $serverVersion, $write);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $changed
     */
    private function applyCleanly(
        SyncEntity $entity,
        Model $record,
        string $operation,
        array $payload,
        array $changed,
        int $serverVersion,
        callable $write,
    ): SyncChange {
        if ($operation === SyncChange::OP_DELETE) {
            $entity->writerInstance()->delete($entity, $record, $serverVersion + 1);

            return $write(SyncChange::APPLIED, $serverVersion + 1);
        }

        if ($changed === []) {
            return $write(SyncChange::APPLIED, $serverVersion);
        }

        $entity->writerInstance()->update($entity, $record, $payload, $serverVersion + 1);

        return $write(SyncChange::APPLIED, $serverVersion + 1);
    }

    /**
     * Last-write-wins over the non-financial fields, judged by the server's
     * `updated_at` — never the device's clock, which docs/09-sync-offline.md §5
     * says outright cannot be trusted.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $changed
     */
    private function merge(
        SyncEntity $entity,
        Model $record,
        array $payload,
        array $changed,
        int $serverVersion,
        callable $write,
    ): SyncChange {
        if (! $this->clientIsNewer($record, $payload)) {
            return $write(SyncChange::MERGED, $serverVersion, 'server_newer');
        }

        // Only the fields that actually differ, so the stale financial values
        // riding along in the same payload cannot slip through.
        $subset = array_intersect_key($payload, array_flip($changed));

        $entity->writerInstance()->update($entity, $record, $subset, $serverVersion + 1);

        return $write(SyncChange::MERGED, $serverVersion + 1);
    }

    /** @param  array<string, mixed>  $payload */
    private function clientIsNewer(Model $record, array $payload): bool
    {
        $claimed = $payload['updated_at'] ?? null;
        $serverUpdatedAt = EntityPayload::timestampOf($record, 'updated_at');

        // No usable stamp on either side and the client's write stands: the
        // alternative is discarding an edit because a timestamp was missing.
        if (! is_string($claimed) || $serverUpdatedAt === null) {
            return true;
        }

        try {
            $claimedAt = new \DateTimeImmutable($claimed);
        } catch (\Exception) {
            return true;
        }

        return $claimedAt->getTimestamp() >= $serverUpdatedAt->getTimestamp();
    }

    /**
     * The verdict this exact change already received, if it has been seen.
     *
     * Matched on what the device sent rather than on a client-supplied request
     * id, so a retry from a different process — or a batch reassembled after a
     * crash — is recognised just the same.
     *
     * @param  array<string, mixed>  $payload
     */
    private function findReplay(
        SyncEntity $entity,
        string $id,
        string $operation,
        int $baseVersion,
        array $payload,
    ): ?SyncChange {
        $canonical = EntityPayload::canonical($entity->writable($payload));

        $candidates = SyncChange::query()
            ->where('entity_type', $entity->key)
            ->where('entity_id', $id)
            ->where('operation', $operation)
            ->where('client_version', $baseVersion)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $candidate) {
            if (EntityPayload::canonical($entity->writable((array) $candidate->payload)) === $canonical) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $payload */
    private function log(
        SyncEntity $entity,
        string $id,
        string $operation,
        int $baseVersion,
        array $payload,
        ?Device $device,
        string $status,
        ?int $serverVersion,
        ?string $reason = null,
    ): SyncChange {
        return SyncChange::query()->create([
            'device_id' => $device?->id,
            'entity_type' => $entity->key,
            'entity_id' => $id,
            'operation' => $operation,
            'payload' => $entity->writable($payload),
            'client_version' => $baseVersion,
            'server_version' => $serverVersion,
            'applied_at' => in_array($status, [SyncChange::APPLIED, SyncChange::MERGED], true) ? now() : null,
            'status' => $status,
            'conflict_reason' => $reason,
        ]);
    }

    /** @return array<string, mixed> */
    private function verdictFor(SyncEntity $entity, string $id, string $operation, SyncChange $log): array
    {
        $verdict = [
            'entity' => $entity->key,
            'id' => $id,
            'op' => $operation,
            'status' => $log->status,
            'server_version' => (int) $log->server_version,
        ];

        if ($log->conflict_reason !== null) {
            $verdict['reason'] = $log->conflict_reason;
        }

        if ($log->status !== SyncChange::CONFLICT) {
            return $verdict;
        }

        // A conflict is useless without the other side of the argument: the app
        // puts both versions in front of the user and lets them pick.
        $record = $entity->query()->find($id);

        $verdict['server_payload'] = $record === null ? null : EntityPayload::of($entity, $record);

        return $verdict;
    }

    private function codeOf(\Throwable $e): string
    {
        return match (true) {
            $e instanceof SyncException => $e->errorCode,
            property_exists($e, 'errorCode') => (string) $e->errorCode,
            default => 'not_applicable',
        };
    }
}
