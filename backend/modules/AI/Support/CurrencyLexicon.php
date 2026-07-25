<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Modules\Core\Models\Workspace;

/**
 * The colloquial currency words people actually type, and the one conversion
 * that is a genuine hazard.
 *
 * Iranians quote prices in toman; the ledger keeps rial. Ten toman is one
 * hundred rial and nobody writes the zeros. docs/08-ai-layer.md calls this out
 * as a top source of error, so the factor is never guessed: it comes from the
 * workspace's own setting, falls back to a named config default, and the parse
 * reports which value it used.
 */
final class CurrencyLexicon
{
    /** A synthetic code: the word means IRR, but only after the factor is applied. */
    public const TOMAN = 'TOMAN';

    /**
     * Matched against TextNormalizer output, so everything here is lowercase
     * with Persian letter forms.
     *
     * @var array<string, list<string>>
     */
    private const WORDS = [
        'TRY' => ['لیر', 'لیره', 'لیرا', 'لیرترکیه', 'try', 'tl', 'lira', 'liras', '₺'],
        'USD' => ['دلار', 'دلاری', 'usd', 'dollar', 'dollars', 'buck', 'bucks', '$'],
        'EUR' => ['یورو', 'یورویی', 'eur', 'euro', 'euros', '€'],
        'AED' => ['درهم', 'aed', 'dirham', 'dirhams'],
        'IRR' => ['ریال', 'ریالی', 'irr', 'rial', 'rials'],
        self::TOMAN => ['تومان', 'تومن', 'تومانی', 'irt', 'toman', 'tomans', 'tuman'],
    ];

    /**
     * Scale words. All of these multiply, none of them are currencies.
     *
     * @var array<string, int>
     */
    private const MULTIPLIERS = [
        'هزار' => 1_000,
        'هزارتا' => 1_000,
        'میلیون' => 1_000_000,
        'ملیون' => 1_000_000,
        'میلیارد' => 1_000_000_000,
        'ملیارد' => 1_000_000_000,
        'k' => 1_000,
        'thousand' => 1_000,
        'm' => 1_000_000,
        'mn' => 1_000_000,
        'million' => 1_000_000,
        'bn' => 1_000_000_000,
        'billion' => 1_000_000_000,
    ];

    /**
     * Every currency word with the byte offset it was found at, earliest first.
     *
     * @return list<array{code: string, word: string, offset: int, length: int}>
     */
    public static function findAll(string $normalized): array
    {
        $hits = [];

        foreach (self::WORDS as $code => $words) {
            foreach ($words as $word) {
                $pattern = '/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/u';

                if (preg_match_all($pattern, $normalized, $m, PREG_OFFSET_CAPTURE) === false) {
                    continue;
                }

                foreach ($m[0] as [$matched, $offset]) {
                    $hits[] = [
                        'code' => $code,
                        'word' => $matched,
                        'offset' => $offset,
                        'length' => strlen($matched),
                    ];
                }
            }
        }

        usort($hits, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        return $hits;
    }

    /** @return list<array{factor: int, word: string, offset: int, length: int}> */
    public static function findMultipliers(string $normalized): array
    {
        $hits = [];

        foreach (self::MULTIPLIERS as $word => $factor) {
            $pattern = '/(?<![\p{L}])'.preg_quote((string) $word, '/').'(?![\p{L}])/u';

            if (preg_match_all($pattern, $normalized, $m, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($m[0] as [$matched, $offset]) {
                $hits[] = ['factor' => $factor, 'word' => $matched, 'offset' => $offset, 'length' => strlen($matched)];
            }
        }

        usort($hits, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        return $hits;
    }

    /**
     * How many rial one toman is worth in this workspace.
     *
     * Workspace setting first, config default second. Both are explicit values
     * a human wrote down; neither is derived from the text being parsed.
     */
    public static function tomanRialFactor(?Workspace $workspace): int
    {
        $key = (string) config('ai.toman.workspace_setting_key', 'ai.toman_rial_factor');
        $configured = data_get($workspace->settings ?? [], $key);

        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $default = (int) config('ai.toman.rial_factor', 10);

        return $default > 0 ? $default : 10;
    }
}
