<?php

declare(strict_types=1);

namespace Modules\Capture\Parsers;

use App\Core\Money\Currency;
use Carbon\CarbonImmutable;
use Modules\AI\Support\DecimalValue;
use Modules\AI\Support\TextNormalizerBridge;
use Modules\Capture\Exceptions\CaptureException;
use Modules\Capture\Support\ParsedQr;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Transaction;
use Throwable;

/**
 * Reads the string a QR code contained. Decoding the pixels happened on the
 * device; this only has to decide what the string means.
 *
 * Exactly two shapes are understood, and anything else is refused. That refusal
 * is the feature: a QR is scanned in a shop, in a hurry, and a payload read as
 * something it is not produces a draft that looks as trustworthy as a correct
 * one. "I do not know what this is" costs the user one manual entry; a wrong
 * confident answer costs them a wrong balance they may never trace.
 */
final readonly class QrParser
{
    /** Keys the simple receipt payload may carry. */
    private const KV_KEYS = [
        'amount', 'total', 'currency', 'merchant', 'seller', 'date',
        'ref', 'reference', 'invoice', 'type', 'tax',
    ];

    /** EMV tag 62 sub-tags that carry a human-usable reference. */
    private const EMV_REFERENCE_SUBTAGS = ['01', '05'];

    public function __construct(private WorkspaceContext $context) {}

    public function parse(string $payload, ?CarbonImmutable $now = null): ParsedQr
    {
        $payload = trim($payload);
        $now ??= CarbonImmutable::now();

        if ($payload === '') {
            throw CaptureException::unrecognisedQr('empty_payload');
        }

        if ($this->looksLikeEmv($payload)) {
            return $this->parseEmv($payload, $now);
        }

        if (str_contains($payload, '=')) {
            return $this->parseKeyValue($payload, $now);
        }

        throw CaptureException::unrecognisedQr('unknown_format');
    }

    private function looksLikeEmv(string $payload): bool
    {
        // Every EMV QR opens with the payload format indicator: tag 00, length
        // 02, value 01.
        return str_starts_with($payload, '000201');
    }

    /**
     * `amount=125000;currency=IRR;merchant=Cafe Naderi;date=2026-07-20`
     *
     * Amounts are major units, as printed on the receipt the code sits on.
     */
    private function parseKeyValue(string $payload, CarbonImmutable $now): ParsedQr
    {
        $fields = [];

        foreach (preg_split('/[;&\r\n]+/u', $payload) ?: [] as $chunk) {
            if (! str_contains($chunk, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $chunk, 2);
            $key = mb_strtolower(trim($key));

            if (in_array($key, self::KV_KEYS, true)) {
                $fields[$key] = trim($value);
            }
        }

        $rawAmount = $fields['amount'] ?? $fields['total'] ?? null;

        // One recognised key is a coincidence; a receipt payload carries an
        // amount and at least one thing to say about it.
        if ($rawAmount === null || count($fields) < 2) {
            throw CaptureException::unrecognisedQr('not_a_receipt_payload');
        }

        $code = strtoupper((string) ($fields['currency'] ?? ''));
        $explicit = Currency::isSupported($code);
        $currency = Currency::of($explicit ? $code : $this->context->baseCurrency());

        $amount = $this->minorUnits($rawAmount, $currency);

        if ($amount === null) {
            throw CaptureException::unrecognisedQr('unreadable_amount');
        }

        $merchant = $fields['merchant'] ?? $fields['seller'] ?? null;

        return new ParsedQr(
            format: ParsedQr::FORMAT_RECEIPT_KV,
            type: ($fields['type'] ?? '') === Transaction::TYPE_INCOME
                ? Transaction::TYPE_INCOME
                : Transaction::TYPE_EXPENSE,
            amount: $amount,
            currency: $currency->code,
            currencyExplicit: $explicit,
            merchant: $merchant === '' ? null : $merchant,
            reference: $fields['ref'] ?? $fields['reference'] ?? $fields['invoice'] ?? null,
            occurredAt: $this->date($fields['date'] ?? null, $now),
            fields: $fields,
        );
    }

    /** EMVCo merchant-presented QR: two-digit tag, two-digit length, value. */
    private function parseEmv(string $payload, CarbonImmutable $now): ParsedQr
    {
        $tags = $this->tlv($payload);

        if ($tags === null) {
            throw CaptureException::unrecognisedQr('malformed_tlv');
        }

        $this->verifyCrc($payload, $tags);

        $rawAmount = $tags['54'] ?? null;
        $numeric = $tags['53'] ?? null;

        // A static EMV code with no amount is a valid payment code and a
        // useless capture: there is no transaction in it to draft.
        if ($rawAmount === null || $numeric === null) {
            throw CaptureException::unrecognisedQr('emv_without_amount');
        }

        // Same coercion as the tags: '364' is an integer key by the time the
        // config array exists, and the lookup below is coerced to match.
        /** @var array<int|string, string> $map */
        $map = (array) config('capture.qr.currency_numeric', []);
        $code = $map[$numeric] ?? null;

        if ($code === null) {
            throw CaptureException::unrecognisedQr('unsupported_currency:'.$numeric);
        }

        $currency = Currency::of($code);
        $amount = $this->minorUnits($rawAmount, $currency);

        if ($amount === null) {
            throw CaptureException::unrecognisedQr('unreadable_amount');
        }

        return new ParsedQr(
            format: ParsedQr::FORMAT_EMV_TLV,
            type: Transaction::TYPE_EXPENSE,
            amount: $amount,
            currency: $currency->code,
            currencyExplicit: true,
            merchant: $tags['59'] ?? null,
            reference: $this->emvReference($tags['62'] ?? null),
            occurredAt: $now->startOfDay(),
            fields: [
                'merchant_city' => $tags['60'] ?? null,
                'country' => $tags['58'] ?? null,
                'tags' => array_keys($tags),
            ],
        );
    }

    /**
     * The payload split into its tags, or null when it is not well-formed TLV.
     *
     * EMV tags are two-digit strings, and PHP turns every one of them that has
     * no leading zero — '54', '63' — into an integer key on the way in. Reads
     * with the same literal are coerced identically, so the lookups work; the
     * key type just has to say so rather than claim they are all strings.
     *
     * @return array<int|string, string>|null
     */
    private function tlv(string $payload): ?array
    {
        $tags = [];
        $offset = 0;
        $length = strlen($payload);

        while ($offset + 4 <= $length) {
            $tag = substr($payload, $offset, 2);
            $size = substr($payload, $offset + 2, 2);

            if (! ctype_digit($tag) || ! ctype_digit($size)) {
                return null;
            }

            $size = (int) $size;

            if ($offset + 4 + $size > $length) {
                return null;
            }

            $tags[$tag] = substr($payload, $offset + 4, $size);
            $offset += 4 + $size;
        }

        // A payload with trailing bytes that are not a complete field was
        // truncated or is not EMV at all. Either way it is not readable.
        return $offset === $length ? $tags : null;
    }

    /** @param array<int|string, string> $tags */
    private function verifyCrc(string $payload, array $tags): void
    {
        $stated = $tags['63'] ?? null;

        if ($stated === null || ! (bool) config('capture.qr.verify_crc', true)) {
            return;
        }

        $marker = strrpos($payload, '6304');

        if ($marker === false) {
            throw CaptureException::qrChecksumMismatch();
        }

        $expected = $this->crc16(substr($payload, 0, $marker + 4));

        if (! hash_equals($expected, strtoupper($stated))) {
            throw CaptureException::qrChecksumMismatch();
        }
    }

    /** CRC-16/CCITT-FALSE, as EMVCo specifies for tag 63. */
    private function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private function emvReference(?string $additional): ?string
    {
        if ($additional === null) {
            return null;
        }

        $sub = $this->tlv($additional);

        if ($sub === null) {
            return null;
        }

        foreach (self::EMV_REFERENCE_SUBTAGS as $tag) {
            if (isset($sub[$tag]) && $sub[$tag] !== '') {
                return $sub[$tag];
            }
        }

        return null;
    }

    private function minorUnits(string $raw, Currency $currency): ?int
    {
        try {
            // Persian digits reach here from receipt payloads printed in Iran;
            // forParsing latinises them and drops the Arabic separator.
            $value = DecimalValue::parse(TextNormalizerBridge::forParsing($raw));
            $minor = $value->toMinorUnits($currency);

            return $minor > 0 ? $minor : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function date(?string $value, CarbonImmutable $now): CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return $now->startOfDay();
        }

        try {
            return CarbonImmutable::parse(trim($value))->startOfDay();
        } catch (Throwable) {
            return $now->startOfDay();
        }
    }
}
