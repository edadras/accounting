<?php

declare(strict_types=1);

namespace Modules\AI\Tools\Concerns;

use Carbon\CarbonImmutable;

/**
 * Turns whatever the model put in `from`/`to` into a sane window.
 *
 * Arguments are model output and therefore untrusted input: an unparseable
 * date falls back to the current month rather than throwing, because a chat
 * that dies on a malformed argument is worse than one that answers about the
 * wrong month and says which month it used.
 */
trait ReadsWindow
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    protected function window(array $arguments): array
    {
        $now = CarbonImmutable::now();

        return [
            $this->date($arguments['from'] ?? null) ?? $now->startOfMonth(),
            $this->date($arguments['to'] ?? null)?->endOfDay() ?? $now->endOfMonth(),
        ];
    }

    protected function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function limit(mixed $value, int $fallback = 20): int
    {
        $max = (int) config('ai.chat.max_rows', 50);

        return max(1, min($max, is_numeric($value) ? (int) $value : $fallback));
    }
}
