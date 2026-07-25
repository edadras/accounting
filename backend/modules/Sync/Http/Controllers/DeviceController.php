<?php

declare(strict_types=1);

namespace Modules\Sync\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Sync\Exceptions\SyncException;
use Modules\Sync\Http\Resources\DeviceResource;
use Modules\Sync\Models\Device;

final class DeviceController
{
    public function index(Request $request): JsonResponse
    {
        $devices = Device::query()
            ->where('user_id', $request->user()?->id)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => DeviceResource::collection($devices),
            'meta' => ['total' => $devices->count(), 'request_id' => $request->header('X-Request-Id')],
        ]);
    }

    /**
     * Registers the device, or re-registers one that is already known.
     *
     * Re-registering has to be harmless: the app calls this on every launch, and
     * the id was minted on the device itself, possibly before it had ever been
     * online.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'platform' => ['sometimes', Rule::in(Device::PLATFORMS)],
            'name' => ['nullable', 'string', 'max:120'],
            'push_token' => ['nullable', 'string', 'max:512'],
        ]);

        $userId = $request->user()?->id;
        $deviceId = isset($data['id']) ? (string) $data['id'] : null;
        $existing = $deviceId !== null
            ? Device::query()->where('user_id', $userId)->find($deviceId)
            : null;

        $attributes = [
            'platform' => $data['platform'] ?? $existing->platform ?? 'unknown',
            'name' => $data['name'] ?? $existing?->name,
            'push_token' => $data['push_token'] ?? $existing?->push_token,
            'last_seen_at' => now(),

            // Re-registering revives a device the user revoked from another
            // phone only if they say so explicitly; silence leaves it revoked.
            'revoked_at' => $existing?->revoked_at,
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return (new DeviceResource($existing))->response();
        }

        $device = new Device;
        $device->fill($attributes + ['id' => $deviceId, 'user_id' => $userId]);
        $device->save();

        return (new DeviceResource($device))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $device = Device::query()
            ->where('user_id', $request->user()?->id)
            ->find($id)
            ?? throw SyncException::deviceNotFound($id);

        // Revoked, not deleted: the sync log points at it, and a user asking
        // "what did that lost phone do?" deserves an answer.
        $device->forceFill(['revoked_at' => now(), 'push_token' => null])->save();

        return (new DeviceResource($device))->response();
    }
}
