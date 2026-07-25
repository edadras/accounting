<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use App\Core\Money\Currency;
use Carbon\CarbonImmutable;
use Modules\Search\Support\TextNormalizer;

/**
 * Reads a till receipt that has already been turned into text.
 *
 * Kept apart from any provider because the OCR pass and the structuring pass
 * are different problems: whether the pixels came back as text through
 * Tesseract or through a vision model, the grammar of a receipt is the same,
 * and it is worth parsing with rules that can be tested against a fixed corpus
 * (docs/08-ai-layer.md §3).
 *
 * Every field carries its own confidence: a labelled «جمع کل» is near certain,
 * a total inferred from the largest number on the page is not.
 */
final class ReceiptTextParser
{
    private const LABEL_TAX = '(?:مالیات بر ارزش افزوده|ارزش افزوده|مالیات|vat|kdv|tax)';

    private const LABEL_TOTAL = '(?:جمع کل|مبلغ کل|قابل پرداخت|جمع نهایی|grand total|genel toplam|toplam|total|amount due)';

    private const LABEL_SUBTOTAL = '(?:جمع اقلام|جمع جزء|subtotal|ara toplam)';

    private const LABEL_DATE = '(?:تاریخ|زمان|date|tarih)';

    private const NUMBER = '([\d][\d,\.]*)';

    /**
     * @return array{
     *   merchant: array{value: string|null, confidence: float},
     *   occurred_at: array{value: string|null, confidence: float},
     *   currency: array{value: string|null, confidence: float},
     *   tax: array{value: int|null, confidence: float},
     *   total: array{value: int|null, confidence: float},
     *   items: array{value: list<array{name: string, quantity: int, unit_price: int, line_total: int}>, confidence: float},
     *   raw_text: string,
     * }
     */
    public static function parse(
        string $text,
        string $defaultCurrency = 'USD',
        int $tomanRialFactor = 10,
        ?CarbonImmutable $now = null,
    ): array {
        $lines = self::lines($text);
        [$currency, $tomanApplied, $currencyStated] = self::currency($lines, $defaultCurrency, $tomanRialFactor);

        $tax = self::labelled($lines, self::LABEL_TAX, $currency, $tomanApplied);

        // "جمع اقلام" contains "جمع"; without the exclusion the subtotal line
        // would be read as the total and the arithmetic check would compare a
        // number against itself.
        $total = self::labelled($lines, self::LABEL_TOTAL, $currency, $tomanApplied, self::LABEL_SUBTOTAL);
        $items = self::items($lines, $currency, $tomanApplied);

        // A missing total stays missing. Filling it in from the lines would
        // manufacture the very number the arithmetic check exists to compare
        // against, and the check would then always agree with itself.
        return [
            'merchant' => self::merchant($lines),
            'occurred_at' => self::date($lines, $now),
            'currency' => ['value' => $currency->code, 'confidence' => $currencyStated ? 0.85 : 0.3],
            'tax' => $tax,
            'total' => $total,
            'items' => $items,
            'raw_text' => trim($text),
        ];
    }

    /** @return list<array{raw: string, folded: string, lower: string}> */
    private static function lines(string $text): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $raw = trim($line);

            if ($raw === '') {
                continue;
            }

            $folded = trim(strtr($raw, TextNormalizer::replacements()));

            $lines[] = [
                'raw' => $raw,
                'folded' => $folded,
                'lower' => mb_strtolower($folded, 'UTF-8'),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array{raw: string, folded: string, lower: string}>  $lines
     * @return array{0: Currency, 1: int, 2: bool}
     */
    private static function currency(array $lines, string $default, int $tomanRialFactor): array
    {
        $joined = implode(' ', array_column($lines, 'lower'));

        foreach (CurrencyLexicon::findAll($joined) as $hit) {
            if ($hit['code'] === CurrencyLexicon::TOMAN) {
                return [Currency::of('IRR'), $tomanRialFactor, true];
            }

            return [Currency::of($hit['code']), 1, true];
        }

        // Nothing printed a currency. The workspace's own is the only honest
        // guess, and it is reported at low confidence so the user checks it.
        return [
            Currency::isSupported($default) ? Currency::of($default) : Currency::of('USD'),
            1,
            false,
        ];
    }

    /**
     * @param  list<array{raw: string, folded: string, lower: string}>  $lines
     * @return array{value: string|null, confidence: float}
     */
    private static function merchant(array $lines): array
    {
        foreach ($lines as $line) {
            // Shops put their name at the top and their address underneath;
            // the first line without a digit in it is nearly always the name.
            if (preg_match('/\d/u', $line['folded']) === 1 || mb_strlen($line['raw']) < 2) {
                continue;
            }

            return ['value' => $line['raw'], 'confidence' => 0.7];
        }

        return ['value' => null, 'confidence' => 0.0];
    }

    /**
     * @param  list<array{raw: string, folded: string, lower: string}>  $lines
     * @return array{value: string|null, confidence: float}
     */
    private static function date(array $lines, ?CarbonImmutable $now): array
    {
        foreach ($lines as $line) {
            if (preg_match('/'.self::LABEL_DATE.'\s*[:：\-]?\s*(.+)$/ui', $line['lower'], $m) !== 1) {
                continue;
            }

            $parsed = self::parseDate(trim($m[1]), $now);

            if ($parsed !== null) {
                return ['value' => $parsed->toIso8601String(), 'confidence' => 0.9];
            }
        }

        foreach ($lines as $line) {
            $parsed = self::parseDate($line['lower'], $now);

            if ($parsed !== null) {
                return ['value' => $parsed->toIso8601String(), 'confidence' => 0.6];
            }
        }

        return ['value' => null, 'confidence' => 0.0];
    }

    private static function parseDate(string $text, ?CarbonImmutable $now): ?CarbonImmutable
    {
        $now ??= CarbonImmutable::now();

        if (preg_match('/(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/u', $text, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

            // Four digits starting with 13 or 14 is a Jalali year, not a
            // Gregorian one: Iranian receipts print 1405/05/03.
            if ($y < 1700) {
                [$y, $mo, $d] = JalaliDate::toGregorian($y, $mo, $d);
            }

            return checkdate($mo, $d, $y) ? $now->setDate($y, $mo, $d)->startOfDay() : null;
        }

        $resolved = RelativeDateResolver::resolve(mb_strtolower($text, 'UTF-8'), $now);

        return $resolved?->date;
    }

    /**
     * @param  list<array{raw: string, folded: string, lower: string}>  $lines
     * @return array{value: int|null, confidence: float}
     */
    private static function labelled(
        array $lines,
        string $label,
        Currency $currency,
        int $tomanFactor,
        ?string $exclude = null,
    ): array {
        foreach ($lines as $line) {
            if ($exclude !== null && preg_match('/'.$exclude.'/ui', $line['lower']) === 1) {
                continue;
            }

            if (preg_match('/'.$label.'\D*'.self::NUMBER.'/ui', $line['lower'], $m) !== 1) {
                continue;
            }

            return [
                'value' => self::toMinor($m[1], $currency, $tomanFactor),
                'confidence' => 0.95,
            ];
        }

        return ['value' => null, 'confidence' => 0.0];
    }

    /**
     * @param  list<array{raw: string, folded: string, lower: string}>  $lines
     * @return array{value: list<array{name: string, quantity: int, unit_price: int, line_total: int}>, confidence: float}
     */
    private static function items(array $lines, Currency $currency, int $tomanFactor): array
    {
        $skip = '/'.self::LABEL_TOTAL.'|'.self::LABEL_TAX.'|'.self::LABEL_SUBTOTAL.'|'.self::LABEL_DATE.'/ui';
        $items = [];
        $certain = 0;

        foreach ($lines as $line) {
            if (preg_match($skip, $line['lower']) === 1) {
                continue;
            }

            // "نان سنگک 2 x 15,000" — quantity and unit price spelled out.
            if (preg_match('/^(.*?)\s+(\d+)\s*[x×\*]\s*'.self::NUMBER.'\s*$/u', $line['folded'], $m) === 1) {
                $quantity = max(1, (int) $m[2]);
                $unit = self::toMinor($m[3], $currency, $tomanFactor);

                $items[] = [
                    'name' => trim($m[1]),
                    'quantity' => $quantity,
                    'unit_price' => $unit,
                    'line_total' => $unit * $quantity,
                ];
                $certain++;

                continue;
            }

            // "شیر 45,000" — a line total with the quantity implied.
            if (preg_match('/^(\D.*?)\s+'.self::NUMBER.'\s*$/u', $line['folded'], $m) === 1) {
                $total = self::toMinor($m[2], $currency, $tomanFactor);

                if ($total <= 0) {
                    continue;
                }

                $items[] = [
                    'name' => trim($m[1]),
                    'quantity' => 1,
                    'unit_price' => $total,
                    'line_total' => $total,
                ];
            }
        }

        if ($items === []) {
            return ['value' => [], 'confidence' => 0.0];
        }

        return ['value' => $items, 'confidence' => $certain === count($items) ? 0.9 : 0.65];
    }

    private static function toMinor(string $number, Currency $currency, int $tomanFactor): int
    {
        $value = DecimalValue::parse($number);

        return ($tomanFactor === 1 ? $value : $value->multipliedBy($tomanFactor))->toMinorUnits($currency);
    }
}
