<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * Last line of defence on the way out (docs/07-security.md §5.4).
 *
 * Tools cannot return another workspace's rows, so an identifier in the answer
 * that did not come from a tool result did not come from the database either —
 * it was hallucinated, or it was echoed back out of injected text. Neither is
 * something to show a user next to their own figures, so it is redacted and
 * the redaction is reported rather than hidden.
 */
final class OutputGuard
{
    /** Crockford base32, the alphabet ULIDs are printed in. */
    private const ULID = '/\b[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}\b/i';

    /**
     * @param  array<string, mixed>|list<mixed>  $toolResults
     * @return array{text: string, redacted: list<string>}
     */
    public static function scrub(string $answer, array $toolResults): array
    {
        $allowed = array_flip(array_map('strtoupper', self::identifiersIn($toolResults)));
        $redacted = [];

        $text = (string) preg_replace_callback(
            self::ULID,
            static function (array $m) use ($allowed, &$redacted): string {
                if (isset($allowed[strtoupper($m[0])])) {
                    return $m[0];
                }

                $redacted[] = $m[0];

                return '[redacted]';
            },
            $answer,
        );

        return ['text' => $text, 'redacted' => array_values(array_unique($redacted))];
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    public static function identifiersIn(array $payload): array
    {
        $found = [];

        array_walk_recursive($payload, static function (mixed $value) use (&$found): void {
            if (is_string($value) && preg_match(self::ULID, $value, $m) === 1) {
                $found[] = $m[0];
            }
        });

        return array_values(array_unique($found));
    }
}
