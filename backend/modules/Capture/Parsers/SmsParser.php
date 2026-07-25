<?php

declare(strict_types=1);

namespace Modules\Capture\Parsers;

use App\Core\Money\Currency;
use Modules\AI\Support\AmountExtractor;
use Modules\AI\Support\DecimalValue;
use Modules\AI\Support\ExtractedAmount;
use Modules\AI\Support\TextNormalizerBridge;
use Modules\Capture\Support\NumberText;
use Modules\Capture\Support\ParsedSms;
use Modules\Core\Support\WorkspaceContext;
use Throwable;

/**
 * Reads a bank SMS through the registry in `Config/sms.php`.
 *
 * No bank is named in this file. A new bank is an entry in that config; if
 * adding one ever needs a change here, the pattern language is missing
 * something and that is the thing to fix.
 *
 * The numbers are not re-derived either — the matched amount and its currency
 * word are handed to Modules\AI\Support\AmountExtractor, which already knows
 * about toman, scale words and the workspace's rial factor. Two
 * implementations of "how much money is this" is one too many.
 */
final readonly class SmsParser
{
    public function __construct(private WorkspaceContext $context) {}

    public function parse(?string $sender, string $body): ?ParsedSms
    {
        $normalized = TextNormalizerBridge::forParsing($body);

        if ($normalized === '') {
            return null;
        }

        $normalizedSender = TextNormalizerBridge::normalize($sender);

        foreach ($this->patterns() as $key => $config) {
            if (! $this->senderAllowed($normalizedSender, $config)) {
                continue;
            }

            $pattern = (string) ($config['pattern'] ?? '');

            if ($pattern === '' || @preg_match($pattern, $normalized, $m) !== 1) {
                continue;
            }

            $parsed = $this->build((string) $key, $config, $m);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function patterns(): array
    {
        /** @var array<string, array<string, mixed>> $patterns */
        $patterns = (array) config('sms.patterns', []);

        return $patterns;
    }

    /** @param array<string, mixed> $config */
    private function senderAllowed(string $sender, array $config): bool
    {
        $senders = (array) ($config['senders'] ?? []);

        if ($senders === []) {
            return true;
        }

        foreach ($senders as $candidate) {
            if (str_contains($sender, TextNormalizerBridge::normalize((string) $candidate))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int|string, string>  $m
     */
    private function build(string $key, array $config, array $m): ?ParsedSms
    {
        $direction = $this->direction($m['direction'] ?? null);

        // A shape matched but the word for which way the money went is not one
        // we know. Booking it either way would be a coin flip on the sign of
        // the entry, so the message stays unparsed.
        if ($direction === null) {
            return null;
        }

        $format = (string) ($config['number_format'] ?? NumberText::PLAIN);
        $fallback = (string) ($config['default_currency'] ?? $this->context->baseCurrency());

        $amount = $this->amount($m['amount'] ?? '', $m['currency'] ?? null, $format, $fallback);

        if ($amount === null) {
            return null;
        }

        return new ParsedSms(
            patternKey: $key,
            bank: (string) ($config['bank'] ?? 'unknown'),
            direction: $direction,
            amount: $amount,
            accountFragment: $this->trimmed($m['account'] ?? null),
            balance: $this->balance($m['balance'] ?? null, $format, $amount),
            matched: trim($m[0] ?? ''),
        );
    }

    private function direction(?string $word): ?string
    {
        $word = TextNormalizerBridge::forParsing($word ?? '');

        if ($word === '') {
            return null;
        }

        /** @var array<string, list<string>> $lexicon */
        $lexicon = (array) config('sms.directions', []);

        foreach ($lexicon as $direction => $words) {
            foreach ((array) $words as $candidate) {
                if ($word === TextNormalizerBridge::forParsing((string) $candidate)) {
                    return (string) $direction;
                }
            }
        }

        return null;
    }

    /**
     * The amount, via the AI layer's extractor.
     *
     * The captured number and currency word are stitched back into the shortest
     * phrase that carries both, which is exactly what AmountExtractor was built
     * to read — including «تومان», whose ten-to-one conversion is the error
     * this product can least afford to make twice in two places.
     */
    private function amount(string $raw, ?string $currencyWord, string $format, string $fallback): ?ExtractedAmount
    {
        $number = NumberText::canonical($raw, $format);

        if ($number === '') {
            return null;
        }

        $phrase = trim($number.' '.TextNormalizerBridge::forParsing($currencyWord ?? ''));

        return AmountExtractor::extract($phrase, $this->context->get(), $fallback);
    }

    /** The balance the bank reported, in the same currency the amount landed in. */
    private function balance(?string $raw, string $format, ExtractedAmount $amount): ?int
    {
        $raw = $this->trimmed($raw);

        if ($raw === null) {
            return null;
        }

        try {
            $value = DecimalValue::parse(NumberText::canonical($raw, $format));

            if ($amount->tomanRialFactor !== null) {
                $value = $value->multipliedBy($amount->tomanRialFactor);
            }

            return $value->toMinorUnits(Currency::of($amount->currency));
        } catch (Throwable) {
            // A balance is informational. Losing it must not cost the amount.
            return null;
        }
    }

    private function trimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
