<?php

declare(strict_types=1);

namespace Modules\Sync\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sync\Actions\PullChanges;
use Modules\Sync\Actions\PushChanges;
use Modules\Sync\Exceptions\SyncException;
use Modules\Sync\Http\Requests\PushChangesRequest;
use Modules\Sync\Models\Device;
use Modules\Sync\Support\Cursor;
use Modules\Sync\Support\EntityPayload;

final class SyncController
{
    public function push(PushChangesRequest $request, PushChanges $action): JsonResponse
    {
        $device = $this->device($request);

        /** @var list<array<string, mixed>> $changes */
        $changes = $request->validated('changes');

        $results = $action->handle($changes, $device);

        $device?->markSeen();

        return response()->json([
            'results' => $results,
            'server_time' => $this->serverTime(),
            'meta' => [
                'device_id' => $device?->id,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ]);
    }

    public function pull(Request $request, PullChanges $action): JsonResponse
    {
        $device = $this->device($request);

        $since = $this->timestamp($request->query('since'));
        $cursor = Cursor::decode((string) $request->query('cursor', ''));
        $limit = $this->limit($request);

        $result = $action->handle($since, $cursor, $limit);

        $device?->markSeen();

        return response()->json([
            'data' => $result['changes'],
            'next_cursor' => $result['next_cursor'],

            // The client anchors its next pull to this, never to its own clock:
            // a device whose date is wrong would otherwise skip its own history
            // or ask for it forever (docs/09-sync-offline.md §5).
            'server_time' => $this->serverTime(),

            'meta' => [
                'limit' => $limit,
                'count' => count($result['changes']),
                'since' => $since?->format(EntityPayload::TIMESTAMP),
                'request_id' => $request->header('X-Request-Id'),
            ],
        ]);
    }

    /**
     * The device the batch came from, if it named one.
     *
     * Only the caller's own devices resolve, so a stolen device id is worth
     * nothing without the token that goes with it.
     */
    private function device(Request $request): ?Device
    {
        $id = $request->input('device_id') ?? $request->header('X-Device-Id');

        if (blank($id)) {
            return null;
        }

        $device = Device::query()
            ->where('user_id', $request->user()?->id)
            ->find((string) $id)
            ?? throw SyncException::deviceNotFound((string) $id);

        if ($device->isRevoked()) {
            throw SyncException::deviceRevoked($device->id);
        }

        return $device;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->utc();
        } catch (\Throwable) {
            throw SyncException::invalidTimestamp((string) $value);
        }
    }

    /**
     * Clamped rather than rejected: an oversized page is the client asking for
     * too much at once, which is not a reason to refuse the sync outright.
     */
    private function limit(Request $request): int
    {
        $requested = (int) $request->query('limit', (string) config('sync.default_limit', 100));

        return max(1, min($requested, (int) config('sync.max_limit', 200)));
    }

    private function serverTime(): string
    {
        return CarbonImmutable::now()->utc()->format(EntityPayload::TIMESTAMP);
    }
}
