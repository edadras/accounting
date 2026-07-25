<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use App\Core\Money\Currency;
use Modules\Core\Models\Workspace;

/**
 * Pulls the amount and its currency out of a sentence, with no model involved.
 *
 * Regex first is not a shortcut — it is the reliable half. A model asked for
 * "the number" will occasionally return a different one, and being wrong about
 * an amount is the one failure a bookkeeping app cannot ship.
 */
final class AmountExtractor
{
    /** How far apart a number and a currency word may sit and still belong together. */
    private const PROXIMITY_BYTES = 40;

    public static function extract(string $normalized, ?Workspace $workspace = null, ?string $fallbackCurrency = null): ?ExtractedAmount
    {
        $numbers = self::numbers($normalized);

        if ($numbers === []) {
            return null;
        }

        $currencies = CurrencyLexicon::findAll($normalized);
        $multipliers = CurrencyLexicon::findMultipliers($normalized);

        [$number, $currencyHit] = self::pair($numbers, $currencies);

        $value = DecimalValue::parse($number['text']);

        $multiplier = self::multiplierFor($number, $currencyHit, $multipliers);
        $value = $multiplier === 1 ? $value : $value->multipliedBy($multiplier);

        $code = $currencyHit['code'] ?? null;
        $tomanFactor = null;

        if ($code === CurrencyLexicon::TOMAN) {
            // The one conversion the product cannot afford to infer.
            $tomanFactor = CurrencyLexicon::tomanRialFactor($workspace);
            $value = $value->multipliedBy($tomanFactor);
            $code = 'IRR';
        }

        $explicit = $code !== null;
        $code ??= $fallbackCurrency ?? 'USD';

        if (! Currency::isSupported($code)) {
            $code = $fallbackCurrency !== null && Currency::isSupported($fallbackCurrency) ? $fallbackCurrency : 'USD';
            $explicit = false;
        }

        $minorUnits = $value->toMinorUnits(Currency::of($code));

        if ($minorUnits <= 0) {
            return null;
        }

        return new ExtractedAmount(
            minorUnits: $minorUnits,
            currency: $code,
            currencyExplicit: $explicit,
            matched: trim($number['text'].($currencyHit !== null ? ' '.$currencyHit['word'] : '')),
            multiplier: $multiplier,
            tomanRialFactor: $tomanFactor,
        );
    }

    /** Blanks a span while keeping every later byte offset where it was. */
    public static function mask(string $text, int $offset, int $length): string
    {
        return substr_replace($text, str_repeat(' ', $length), $offset, $length);
    }

    /** @return list<array{text: string, offset: int, length: int}> */
    private static function numbers(string $normalized): array
    {
        if (preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $normalized, $m, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $numbers = [];

        foreach ($m[0] as [$text, $offset]) {
            $numbers[] = ['text' => $text, 'offset' => $offset, 'length' => strlen($text)];
        }

        return $numbers;
    }

    /**
     * Picks which number is the amount.
     *
     * The one standing next to a currency word wins; "۵ شهریور ۳۵۰ لیر" must
     * read 350, not 5. With no currency word at all the first number is taken,
     * because that is where people put the amount.
     *
     * @param  list<array{text: string, offset: int, length: int}>  $numbers
     * @param  list<array{code: string, word: string, offset: int, length: int}>  $currencies
     * @return array{0: array{text: string, offset: int, length: int}, 1: array{code: string, word: string, offset: int, length: int}|null}
     */
    private static function pair(array $numbers, array $currencies): array
    {
        $best = null;
        $bestGap = PHP_INT_MAX;

        foreach ($numbers as $number) {
            foreach ($currencies as $currency) {
                $gap = $currency['offset'] >= $number['offset'] + $number['length']
                    ? $currency['offset'] - ($number['offset'] + $number['length'])
                    : $number['offset'] - ($currency['offset'] + $currency['length']);

                if ($gap < 0 || $gap > self::PROXIMITY_BYTES || $gap >= $bestGap) {
                    continue;
                }

                $bestGap = $gap;
                $best = [$number, $currency];
            }
        }

        return $best ?? [$numbers[0], null];
    }

    /**
     * The scale word that belongs to this number: it sits after it and before
     * the currency word, as in «۳۵۰ هزار تومان».
     *
     * @param  array{text: string, offset: int, length: int}  $number
     * @param  array{code: string, word: string, offset: int, length: int}|null  $currency
     * @param  list<array{factor: int, word: string, offset: int, length: int}>  $multipliers
     */
    private static function multiplierFor(array $number, ?array $currency, array $multipliers): int
    {
        $after = $number['offset'] + $number['length'];
        $limit = $currency !== null && $currency['offset'] > $after
            ? $currency['offset']
            : $after + self::PROXIMITY_BYTES;

        $factor = 1;

        foreach ($multipliers as $hit) {
            if ($hit['offset'] >= $after && $hit['offset'] < $limit) {
                $factor *= $hit['factor'];
            }
        }

        return $factor;
    }
}
