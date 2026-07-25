<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use App\Core\Money\Currency;
use Carbon\CarbonImmutable;
use Modules\AI\Contracts\AiProvider;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Support\AiSwitch;
use Modules\AI\Support\ReceiptDraft;
use Modules\AI\Support\TransactionDraft;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Transaction;

/**
 * A photographed receipt, read into a draft.
 *
 * The interesting part is not the extraction — it is the audit. A receipt is
 * one of the few documents that states its own answer and also shows its
 * working, so the total can be checked instead of believed:
 *
 *     Σ(line totals) + tax ≟ printed total
 *
 * When those disagree something was misread — a digit, a line, the tax — and
 * the honest response is to say so. Quietly taking the printed total, which is
 * the tempting shortcut because it is usually right, means the one receipt in
 * fifty that was misread enters the books looking exactly as confident as the
 * other forty-nine.
 */
final readonly class ExtractReceipt
{
    public function __construct(
        private WorkspaceContext $context,
        private AiProvider $provider,
        private SuggestClassification $suggest,
    ) {}

    /** @param array{now?: CarbonImmutable, source?: string} $options */
    public function handle(string $path, array $options = []): ReceiptDraft
    {
        $workspace = $this->context->require();
        AiSwitch::assertEnabled($workspace);

        $now = $options['now'] ?? CarbonImmutable::now();
        $fields = $this->provider->extractReceipt($path);

        $currencyCode = (string) ($fields['currency']['value'] ?? '');
        $currency = Currency::isSupported($currencyCode)
            ? Currency::of($currencyCode)
            : Currency::of((string) $workspace->base_currency);

        $items = array_values(array_filter(
            (array) ($fields['items']['value'] ?? []),
            static fn (mixed $item): bool => is_array($item),
        ));

        $tax = (int) ($fields['tax']['value'] ?? 0);
        $stated = $fields['total']['value'] ?? null;
        $stated = $stated === null ? null : (int) $stated;

        $arithmetic = $this->audit($items, $tax, $stated);

        $warnings = [];

        if ($stated === null) {
            $warnings[] = 'total_missing';
        }

        if ($items === []) {
            $warnings[] = 'no_line_items';
        }

        if ($arithmetic['checked'] && ! $arithmetic['ok']) {
            $warnings[] = 'arithmetic_mismatch';
        }

        if (($fields['merchant']['value'] ?? null) === null) {
            $warnings[] = 'merchant_missing';
        }

        $amount = $stated ?? $arithmetic['expected_total'];

        if ($amount <= 0) {
            throw AiException::noAmountFound(basename($path));
        }

        $confidence = $this->score($fields, $arithmetic);
        $merchant = $fields['merchant']['value'] ?? null;
        $description = is_string($merchant) && $merchant !== '' ? $merchant : basename($path);

        $occurredAt = $this->occurredAt($fields['occurred_at']['value'] ?? null, $now);

        $transaction = new TransactionDraft(
            type: Transaction::TYPE_EXPENSE,
            amount: $amount,
            currency: $currency->code,
            occurredAt: $occurredAt,
            description: $description,
            categorySuggestion: $this->suggest->category(
                trim($description.' '.implode(' ', array_column($items, 'name'))),
                Transaction::TYPE_EXPENSE,
            ),
            accountSuggestion: $this->suggest->account($currency->code),
            confidence: $confidence,
            warnings: $warnings,
            meta: [
                'source' => $options['source'] ?? 'ocr',
                'provider' => $this->provider->name(),
                'arithmetic' => $arithmetic,
                'document' => basename($path),
            ],
        );

        return new ReceiptDraft(
            fields: $this->withCurrency($fields, $currency->code),
            items: $items,
            arithmetic: $arithmetic,
            confidence: $confidence,
            warnings: $warnings,
            transaction: $transaction,
            rawText: is_string($fields['raw_text'] ?? null) ? $fields['raw_text'] : null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{checked: bool, ok: bool, items_total: int, tax: int, expected_total: int, stated_total: int|null, difference: int, tolerance: int}
     */
    private function audit(array $items, int $tax, ?int $stated): array
    {
        $itemsTotal = 0;

        foreach ($items as $item) {
            $itemsTotal += (int) ($item['line_total'] ?? 0);
        }

        $expected = $itemsTotal + $tax;
        $tolerance = max(0, (int) config('ai.receipt.tolerance_minor_units', 0));

        // Nothing to check against when either side is missing; saying "not
        // checked" is different from saying "checked and fine".
        $checked = $stated !== null && $items !== [];
        $difference = $stated === null ? 0 : $stated - $expected;

        return [
            'checked' => $checked,
            'ok' => $checked ? abs($difference) <= $tolerance : false,
            'items_total' => $itemsTotal,
            'tax' => $tax,
            'expected_total' => $expected,
            'stated_total' => $stated,
            'difference' => $difference,
            'tolerance' => $tolerance,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array{checked: bool, ok: bool}  $arithmetic
     */
    private function score(array $fields, array $arithmetic): float
    {
        $weights = ['total' => 0.4, 'merchant' => 0.2, 'occurred_at' => 0.15, 'currency' => 0.1, 'items' => 0.15];
        $score = 0.0;

        foreach ($weights as $key => $weight) {
            $score += $weight * (float) ($fields[$key]['confidence'] ?? 0.0);
        }

        // A receipt that does not add up may still be mostly right, but it is
        // not a receipt anyone should confirm without looking.
        return round($arithmetic['checked'] && ! $arithmetic['ok'] ? $score * 0.5 : $score, 3);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, array{value: mixed, confidence: float}>
     */
    private function withCurrency(array $fields, string $code): array
    {
        $out = [];

        foreach (['merchant', 'occurred_at', 'currency', 'tax', 'total', 'items'] as $key) {
            $field = is_array($fields[$key] ?? null) ? $fields[$key] : ['value' => null, 'confidence' => 0.0];

            $out[$key] = [
                'value' => $key === 'currency' ? $code : ($field['value'] ?? null),
                'confidence' => round((float) ($field['confidence'] ?? 0.0), 3),
            ];
        }

        return $out;
    }

    private function occurredAt(mixed $value, CarbonImmutable $now): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return $now->startOfDay();
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return $now->startOfDay();
        }
    }
}
