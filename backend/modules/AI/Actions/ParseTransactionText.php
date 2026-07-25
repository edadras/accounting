<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Contracts\AiProvider;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Support\AiSwitch;
use Modules\AI\Support\AmountExtractor;
use Modules\AI\Support\CategoryLexicon;
use Modules\AI\Support\RelativeDateResolver;
use Modules\AI\Support\TextNormalizerBridge;
use Modules\AI\Support\TransactionDraft;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Transaction;

/**
 * «دیروز ۳۵۰ لیر برای شام پرداخت کردم» → a draft.
 *
 * The pipeline of docs/08-ai-layer.md §1, in the order that matters: normalise,
 * then take the amount, the currency and the date with regex, and only then ask
 * a model — and only about the category.
 *
 * That order is the whole design. The facts a model must never be trusted with
 * are precisely the ones a regex is perfect at, and the judgement a regex
 * cannot make ("شام is a restaurant expense") is cheap to get wrong. So the
 * numbers are extracted deterministically and the model advises on a label,
 * with the result of both being a draft the user still has to confirm.
 */
final readonly class ParseTransactionText
{
    public function __construct(
        private WorkspaceContext $context,
        private AiProvider $provider,
        private SuggestClassification $suggest,
    ) {}

    /** @param array{now?: CarbonImmutable, source?: string} $options */
    public function handle(string $text, array $options = []): TransactionDraft
    {
        $workspace = $this->context->require();
        AiSwitch::assertEnabled($workspace);

        if (trim($text) === '') {
            throw AiException::emptyInput('text');
        }

        $now = $options['now'] ?? CarbonImmutable::now();
        $normalized = TextNormalizerBridge::forParsing($text);

        $date = RelativeDateResolver::resolve($normalized, $now);

        // The date's digits are blanked before the amount is read, so «۵ شهریور
        // ۳۵۰ لیر» cannot be recorded as five lira.
        $forAmount = $date === null
            ? $normalized
            : AmountExtractor::mask($normalized, $date->offset, $date->length);

        $amount = AmountExtractor::extract($forAmount, $workspace, $workspace->base_currency);

        if ($amount === null) {
            throw AiException::noAmountFound($text);
        }

        $type = CategoryLexicon::looksLikeIncome($normalized)
            ? Transaction::TYPE_INCOME
            : Transaction::TYPE_EXPENSE;

        $description = $this->purpose($text) ?? trim($text);
        $category = $this->suggest->category($description, $type);
        $account = $this->suggest->account($amount->currency);

        $warnings = [];

        if (! $amount->currencyExplicit) {
            $warnings[] = 'currency_assumed';
        }

        if ($date === null) {
            $warnings[] = 'date_assumed';
        }

        if ($category === null) {
            $warnings[] = 'no_category_match';
        }

        if ($account === null) {
            $warnings[] = 'no_account_in_currency';
        }

        if ($amount->tomanRialFactor !== null) {
            // Surfaced deliberately: the user should see that ten rial per
            // toman was applied, not discover it from a balance being wrong.
            $warnings[] = 'toman_converted_at_'.$amount->tomanRialFactor;
        }

        return new TransactionDraft(
            type: $type,
            amount: $amount->minorUnits,
            currency: $amount->currency,
            occurredAt: $date->date ?? $now->startOfDay(),
            description: $description,
            categorySuggestion: $category,
            accountSuggestion: $account,
            confidence: $this->score($amount->currencyExplicit, $date !== null, $category, $account !== null),
            warnings: $warnings,
            meta: [
                'source' => $options['source'] ?? 'text',
                'provider' => $this->provider->name(),
                'normalized_text' => $normalized,
                'amount_match' => $amount->toArray(),
                'date_expression' => $date?->expression,
            ],
        );
    }

    /**
     * How much of the draft was read rather than assumed.
     *
     * Deliberately additive and boring: the user is shown this number, and a
     * score they can reason about beats a calibrated one they cannot.
     *
     * @param  array{confidence: float}|null  $category
     */
    private function score(bool $currencyExplicit, bool $dateExplicit, ?array $category, bool $hasAccount): float
    {
        $score = 0.35 + 0.30; // A parse only exists at all once an amount was found.

        $score += $currencyExplicit ? 0.13 : 0.0;
        $score += $dateExplicit ? 0.07 : 0.0;
        $score += $hasAccount ? 0.02 : 0.0;
        $score += $category !== null ? 0.05 * $category['confidence'] : 0.0;

        return round(min($score, 0.98), 3);
    }

    /** "برای شام" / "for dinner" — what the money was actually for. */
    private function purpose(string $text): ?string
    {
        // A lookahead, not a match: the verb that ends the phrase marks where
        // the purpose stops without becoming part of it. \b is no help here —
        // PCRE's word boundary is ASCII-only, and none of these letters are.
        $pattern = '/(?:برای|بابت|جهت|for)\s+(.{1,60}?)'
            .'(?=\s+(?:پرداخت|دادم|خریدم|حساب|شد|کردم|کردیم|paid|spent)(?:\s|$)|[.,؛;!?]|$)/u';

        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        $purpose = trim($m[1]);

        return $purpose === '' ? null : $purpose;
    }
}
