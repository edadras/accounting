<?php

declare(strict_types=1);

namespace Modules\Capture\Support;

use Carbon\CarbonImmutable;
use Modules\Capture\Models\CaptureMessage;

/**
 * Writes the inbound-message row and answers the one question every channel
 * has to ask first: have I seen this already?
 *
 * Kept in one place so a new channel cannot accidentally ship without dedupe —
 * the failure mode of which is a duplicate draft the user confirms, and a
 * payment in the books that never happened.
 */
final class MessageRecorder
{
    /** The earlier message this one repeats, or null when it is new. */
    public function duplicateOf(string $channel, string $dedupeKey): ?CaptureMessage
    {
        if (! in_array($channel, (array) config('capture.dedupe.channels', []), true)) {
            return null;
        }

        $hours = max(1, (int) config('capture.dedupe.window_hours', 72));

        return CaptureMessage::query()
            ->where('channel', $channel)
            ->where('dedupe_key', $dedupeKey)
            ->whereIn('status', [CaptureMessage::STATUS_PARSED, CaptureMessage::STATUS_UNPARSED])
            ->where('received_at', '>=', CarbonImmutable::now()->subHours($hours))
            ->orderBy('created_at')
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    public function store(array $attributes): CaptureMessage
    {
        return CaptureMessage::query()->create($attributes);
    }

    /**
     * The row for a redelivery: kept, marked, and pointed at the message it
     * repeats, so "the SMS arrived twice" stays visible instead of looking like
     * the second one was never received.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function storeDuplicate(array $attributes, CaptureMessage $original): CaptureMessage
    {
        return $this->store(array_merge($attributes, [
            'status' => CaptureMessage::STATUS_DUPLICATE,
            'duplicate_of_id' => $original->id,
            'ai_draft_id' => $original->ai_draft_id,
            'matched_pattern' => $original->matched_pattern,
            'reason' => 'already_captured',
        ]));
    }
}
