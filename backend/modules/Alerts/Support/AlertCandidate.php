<?php

declare(strict_types=1);

namespace Modules\Alerts\Support;

use Carbon\CarbonImmutable;

/**
 * An alert a scanner believes is justified, before anyone has decided whether
 * it is new, who it is for or when it may be delivered.
 */
final readonly class AlertCandidate
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public string $type,
        public string $dedupeKey,
        public array $payload,
        public CarbonImmutable $scheduledAt,
    ) {}
}
