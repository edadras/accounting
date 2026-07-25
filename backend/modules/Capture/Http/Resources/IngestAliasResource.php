<?php

declare(strict_types=1);

namespace Modules\Capture\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Capture\Models\IngestAlias;

/**
 * @mixin IngestAlias
 *
 * The address itself appears only on the response to the call that created it,
 * where it is set explicitly. There is no listing that can hand it back, which
 * is the point of storing only the hash.
 */
final class IngestAliasResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'label' => $this->label,
            'revoked' => $this->isRevoked(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
