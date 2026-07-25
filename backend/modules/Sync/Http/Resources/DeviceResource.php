<?php

declare(strict_types=1);

namespace Modules\Sync\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sync\Models\Device;
use Modules\Sync\Support\EntityPayload;

/**
 * @mixin Device
 */
final class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'device',
            'platform' => $this->platform,
            'name' => $this->name,

            // The push token is never echoed back: it is a credential, and a
            // device list is one of the easiest screens to leave open.
            'has_push_token' => $this->push_token !== null,

            'last_seen_at' => EntityPayload::wire($this->last_seen_at),
            'revoked_at' => EntityPayload::wire($this->revoked_at),
            'created_at' => EntityPayload::wire($this->created_at),
        ];
    }
}
