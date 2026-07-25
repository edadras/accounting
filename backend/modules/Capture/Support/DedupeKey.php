<?php

declare(strict_types=1);

namespace Modules\Capture\Support;

use Modules\AI\Support\TextNormalizerBridge;

/**
 * The fingerprint that makes a redelivered message recognisable.
 *
 * Derived from content, never from arrival time on our side, because the two
 * deliveries of one SMS differ precisely in when we saw them. The sender's own
 * timestamp *is* included, at minute resolution: it is identical across
 * retries, and keeping it means two genuinely separate purchases of the same
 * amount from the same shop an hour apart are still two transactions.
 */
final class DedupeKey
{
    public static function for(string $channel, string ...$parts): string
    {
        $normalized = array_map(
            static fn (string $part): string => TextNormalizerBridge::forParsing($part),
            $parts,
        );

        return hash('sha256', $channel."\0".implode("\0", $normalized));
    }
}
