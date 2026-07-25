<?php

declare(strict_types=1);

namespace Modules\AI\Providers\Gateway;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Modules\AI\Contracts\AiProvider;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Support\AiResponse;
use Modules\AI\Support\CategoryLexicon;
use Modules\AI\Support\CurrencyLexicon;
use Modules\AI\Support\PromptBuilder;
use Modules\AI\Support\ReceiptTextParser;
use Modules\AI\Support\TextNormalizerBridge;
use Modules\AI\Support\ToolCall;
use Modules\Core\Support\WorkspaceContext;

/**
 * A working AI layer with no model behind it.
 *
 * This is not a stub. With no key configured — in the test suite, in a local
 * checkout, and for any user who has opted out of sending data to a third
 * party — this is what runs, and it has to give a real answer: categorise from
 * vocabulary, pick tools from the question, read a receipt, and phrase an
 * insight from the numbers that were computed for it.
 *
 * What it cannot do is the long tail: an unknown merchant, a sentence with two
 * purchases in it, a free-form question that does not resemble any of the
 * shapes below. That is exactly the work a real model is bought for.
 *
 * It never reads intent out of tool results or receipt text. Only the question
 * the user typed selects tools, which is what makes an injected «ignore
 * previous instructions» in a shop name inert rather than merely unlikely to
 * work.
 */
final class DeterministicProvider implements AiProvider
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function name(): string
    {
        return 'deterministic';
    }

    public function complete(string $system, string $user, array $tools = []): AiResponse
    {
        return match (PromptBuilder::taskOf($system)) {
            PromptBuilder::TASK_CATEGORIZE => $this->categorize($user),
            PromptBuilder::TASK_CHAT => $this->chat($user, $tools),
            PromptBuilder::TASK_PHRASE_INSIGHT => $this->phrase($user),
            default => new AiResponse('', [], $this->name()),
        };
    }

    public function transcribe(string $audioPath): string
    {
        if (! is_readable($audioPath)) {
            throw AiException::fileNotReadable($audioPath);
        }

        $contents = (string) file_get_contents($audioPath);

        // A recording carrying its own transcript is how the fixtures and the
        // offline path work. Actual speech needs a speech model, and saying so
        // beats inventing words the user never said.
        if (! $this->looksLikeText($contents)) {
            throw AiException::transcriptionUnavailable($audioPath);
        }

        return trim($contents);
    }

    public function extractReceipt(string $imagePath): array
    {
        if (! is_readable($imagePath)) {
            throw AiException::fileNotReadable($imagePath);
        }

        $contents = (string) file_get_contents($imagePath);

        if (! $this->looksLikeText($contents)) {
            // Pixels need OCR. Every field comes back empty at zero confidence
            // so the draft is honest about knowing nothing.
            return [
                'merchant' => ['value' => null, 'confidence' => 0.0],
                'occurred_at' => ['value' => null, 'confidence' => 0.0],
                'currency' => ['value' => null, 'confidence' => 0.0],
                'tax' => ['value' => null, 'confidence' => 0.0],
                'total' => ['value' => null, 'confidence' => 0.0],
                'items' => ['value' => [], 'confidence' => 0.0],
                'raw_text' => '',
                'note' => 'no_ocr_engine',
            ];
        }

        return ReceiptTextParser::parse(
            $contents,
            $this->context->baseCurrency(),
            CurrencyLexicon::tomanRialFactor($this->context->get()),
        );
    }

    /**
     * Category from vocabulary.
     *
     * The description arrives as delimited data and is only ever read as words
     * to match against; nothing in it can change which categories are on offer,
     * because those came from the server.
     */
    private function categorize(string $payload): AiResponse
    {
        $description = TextNormalizerBridge::forParsing(PromptBuilder::extract($payload, 'description') ?? '');
        $candidates = PromptBuilder::extractJson($payload, 'categories');
        $match = CategoryLexicon::match($description);

        if ($match === null || $candidates === []) {
            return $this->jsonResponse(['category_id' => null, 'confidence' => 0.0, 'reason' => 'no_keyword_match']);
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $path = (string) ($candidate['path'] ?? '');
            $name = TextNormalizerBridge::normalize((string) ($candidate['name'] ?? ''));

            if (str_ends_with($path, '/'.$match['slug']) || $name === $match['slug']) {
                return $this->jsonResponse([
                    'category_id' => (string) ($candidate['id'] ?? ''),
                    'path' => $path,
                    'confidence' => $match['confidence'],
                    'reason' => 'keyword:'.$match['keyword'],
                ]);
            }
        }

        return $this->jsonResponse([
            'category_id' => null,
            'confidence' => 0.0,
            'reason' => 'slug_not_in_workspace:'.$match['slug'],
        ]);
    }

    /** @param list<array<string, mixed>> $tools */
    private function chat(string $payload, array $tools): AiResponse
    {
        $results = PromptBuilder::extractJson($payload, 'tool_results');

        if ($results !== []) {
            return new AiResponse($this->compose($results, $this->locale()), [], $this->name());
        }

        $question = TextNormalizerBridge::forParsing(PromptBuilder::extract($payload, 'question') ?? '');
        $offered = array_map(static fn (array $tool) => (string) ($tool['name'] ?? ''), $tools);
        $wanted = array_values(array_intersect($this->toolsFor($question), $offered));

        if ($wanted === []) {
            $wanted = array_slice($offered, 0, 1);
        }

        return new AiResponse('', array_map(
            fn (string $name) => new ToolCall($name, $this->argumentsFor($name, $question), 'call_'.$name),
            $wanted,
        ), $this->name());
    }

    /** @return list<string> */
    private function toolsFor(string $question): array
    {
        $intents = [
            'get_upcoming_obligations' => ['قبض', 'چک', 'قسط', 'سررسید', 'عقب افتاده', 'سررسیده', 'bill', 'bills', 'due', 'installment', 'cheque', 'check', 'upcoming', 'overdue'],
            'get_cashflow_forecast' => ['پیش بینی', 'جریان نقدی', 'تا پایان ماه', 'کم بیارم', 'کمبود', 'forecast', 'cash flow', 'cashflow', 'end of the month', 'runway'],
            'get_budget_status' => ['بودجه', 'سقف', 'budget', 'budgets'],
            'get_account_balances' => ['موجودی', 'مانده', 'حسابهایم', 'balance', 'balances', 'accounts'],
            'get_transactions' => ['تراکنش', 'فهرست', 'لیست', 'نشان بده', 'خریدهای', 'transactions', 'list', 'show me', 'show my'],
            'get_spending_summary' => ['خرج', 'هزینه', 'پولم کجا رفت', 'مجموع', 'چقدر', 'spend', 'spent', 'spending', 'summary', 'where did my money', 'how much'],
        ];

        $wanted = [];

        foreach ($intents as $tool => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($question, TextNormalizerBridge::normalize($keyword))) {
                    $wanted[] = $tool;

                    break;
                }
            }
        }

        return $wanted;
    }

    /** @return array<string, mixed> */
    private function argumentsFor(string $name, string $question): array
    {
        return match ($name) {
            'get_transactions' => ['limit' => 20] + $this->window($question),
            'get_spending_summary' => ['group_by' => 'category'] + $this->window($question),
            'get_upcoming_obligations', 'get_cashflow_forecast' => ['days' => 30],
            default => [],
        };
    }

    /** @return array<string, string> */
    private function window(string $question): array
    {
        $now = CarbonImmutable::now();

        $range = match (true) {
            str_contains($question, 'امسال') || str_contains($question, 'this year') => [$now->startOfYear(), $now->endOfYear()],
            str_contains($question, 'ماه پیش') || str_contains($question, 'last month') => [
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->subMonthNoOverflow()->endOfMonth(),
            ],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };

        return ['from' => $range[0]->toDateString(), 'to' => $range[1]->toDateString()];
    }

    /**
     * Turns tool output into an answer, always naming what it was derived from
     * (docs/08-ai-layer.md §5: every answer cites its source).
     *
     * @param  array<string, mixed>  $results
     */
    private function compose(array $results, string $locale): string
    {
        $fa = $locale === 'fa';
        $parts = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $tool = (string) ($result['tool'] ?? '');
            $data = is_array($result['result'] ?? null) ? $result['result'] : [];

            $parts[] = match ($tool) {
                'get_spending_summary' => $this->composeSummary($data, $fa),
                'get_transactions' => $this->composeTransactions($data, $fa),
                'get_account_balances' => $this->composeBalances($data, $fa),
                'get_budget_status' => $this->composeBudgets($data, $fa),
                'get_upcoming_obligations' => $this->composeObligations($data, $fa),
                'get_cashflow_forecast' => $this->composeForecast($data, $fa),
                default => '',
            };
        }

        $parts = array_values(array_filter($parts));

        return $parts === []
            ? ($fa ? 'داده‌ای برای پاسخ به این پرسش پیدا نشد.' : 'There is no data here to answer that.')
            : implode("\n\n", $parts);
    }

    /** @param array<string, mixed> $data */
    private function composeSummary(array $data, bool $fa): string
    {
        $total = $this->money((int) ($data['total'] ?? 0), (string) ($data['currency'] ?? 'USD'));
        $count = (int) ($data['transaction_count'] ?? 0);
        $from = (string) ($data['from'] ?? '');
        $to = (string) ($data['to'] ?? '');

        $lines = [$fa
            ? "بر اساس {$count} تراکنش بین {$from} و {$to}، مجموع هزینه {$total} بوده است."
            : "Based on {$count} transactions between {$from} and {$to}, total spending was {$total}."];

        foreach (array_slice((array) ($data['groups'] ?? []), 0, 5) as $group) {
            $share = (int) round(((float) ($group['share'] ?? 0)) * 100);
            $amount = $this->money((int) ($group['amount'] ?? 0), (string) ($data['currency'] ?? 'USD'));
            $lines[] = '- '.($group['label'] ?? '?').": {$amount} ({$share}%)";
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function composeTransactions(array $data, bool $fa): string
    {
        $rows = (array) ($data['transactions'] ?? []);
        $count = count($rows);

        $lines = [$fa ? "{$count} تراکنش پیدا شد:" : "Found {$count} transactions:"];

        foreach ($rows as $row) {
            $amount = $this->money((int) ($row['amount'] ?? 0), (string) ($row['currency'] ?? 'USD'));
            $lines[] = '- '.($row['occurred_at'] ?? '?')." {$amount} — ".($row['description'] ?? '')
                .(($row['category'] ?? null) !== null ? ' ['.$row['category'].']' : '');
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function composeBalances(array $data, bool $fa): string
    {
        $lines = [$fa ? 'موجودی حساب‌ها:' : 'Account balances:'];

        foreach ((array) ($data['accounts'] ?? []) as $account) {
            $lines[] = '- '.($account['name'] ?? '?').': '
                .$this->money((int) ($account['balance'] ?? 0), (string) ($account['currency'] ?? 'USD'));
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function composeBudgets(array $data, bool $fa): string
    {
        $budgets = (array) ($data['budgets'] ?? []);

        if ($budgets === []) {
            return $fa ? 'هیچ بودجه‌ای برای این دوره تعریف نشده است.' : 'No budget is set for this period.';
        }

        $lines = [$fa ? 'وضعیت بودجه‌ها:' : 'Budget status:'];

        foreach ($budgets as $budget) {
            $currency = (string) ($budget['currency'] ?? 'USD');
            $lines[] = '- '.($budget['name'] ?? '?').': '
                .$this->money((int) ($budget['spent'] ?? 0), $currency).' / '
                .$this->money((int) ($budget['limit'] ?? 0), $currency)
                .' ('.(int) ($budget['usage_percent'] ?? 0).'%)';
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function composeObligations(array $data, bool $fa): string
    {
        $rows = (array) ($data['obligations'] ?? []);

        if ($rows === []) {
            return $fa ? 'تعهد سررسیدشده‌ای در این بازه نیست.' : 'Nothing falls due in that window.';
        }

        $lines = [$fa ? 'تعهدات پیش رو:' : 'Upcoming obligations:'];

        foreach ($rows as $row) {
            $lines[] = '- '.($row['due_date'] ?? '?').' '.($row['title'] ?? $row['kind'] ?? '?').': '
                .$this->money((int) ($row['amount'] ?? 0), (string) ($row['currency'] ?? 'USD'));
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $data */
    private function composeForecast(array $data, bool $fa): string
    {
        $currency = (string) ($data['currency'] ?? 'USD');
        $projected = $this->money((int) ($data['projected_balance'] ?? 0), $currency);
        $days = (int) ($data['horizon_days'] ?? 30);
        $obligations = $this->money((int) ($data['obligations'] ?? 0), $currency);

        return $fa
            ? "با ادامهٔ روند {$days} روز گذشته و با احتساب {$obligations} تعهد، موجودی پیش‌بینی‌شده تا "
                .($data['to'] ?? '')." برابر {$projected} است."
            : "Carrying the last {$days} days forward and allowing {$obligations} of obligations, the projected "
                .'balance on '.($data['to'] ?? '')." is {$projected}.";
    }

    private function phrase(string $payload): AiResponse
    {
        $insight = PromptBuilder::extractJson($payload, 'insight');
        $fa = $this->locale() === 'fa';
        $data = is_array($insight['data'] ?? null) ? $insight['data'] : [];
        $currency = (string) ($data['currency'] ?? 'USD');

        $body = match ((string) ($insight['type'] ?? '')) {
            'spending_composition' => $fa
                ? sprintf('%d%% هزینه‌های شما مربوط به %s است.', (int) round(((float) ($data['share'] ?? 0)) * 100), (string) ($data['category'] ?? '؟'))
                : sprintf('%d%% of your spending went to %s.', (int) round(((float) ($data['share'] ?? 0)) * 100), (string) ($data['category'] ?? '?')),

            'period_change' => $this->phraseChange($data, $currency, $fa),

            'spending_anomaly' => $fa
                ? sprintf(
                    'هزینهٔ %s برابر %s بود، در حالی که میانگین این دسته %s است.',
                    (string) ($data['category'] ?? '؟'),
                    $this->money((int) ($data['amount'] ?? 0), $currency),
                    $this->money((int) round((float) ($data['baseline_mean'] ?? 0)), $currency),
                )
                : sprintf(
                    'A %s expense of %s stands against an average of %s for that category.',
                    (string) ($data['category'] ?? '?'),
                    $this->money((int) ($data['amount'] ?? 0), $currency),
                    $this->money((int) round((float) ($data['baseline_mean'] ?? 0)), $currency),
                ),

            'cashflow_forecast' => $fa
                ? sprintf(
                    'اگر همین روند ادامه پیدا کند، تا %s موجودی شما %s خواهد بود.',
                    (string) ($data['to'] ?? ''),
                    $this->money((int) ($data['projected_balance'] ?? 0), $currency),
                )
                : sprintf(
                    'If this trend continues, your balance on %s will be %s.',
                    (string) ($data['to'] ?? ''),
                    $this->money((int) ($data['projected_balance'] ?? 0), $currency),
                ),

            default => '',
        };

        return new AiResponse($body, [], $this->name());
    }

    /** @param array<string, mixed> $data */
    private function phraseChange(array $data, string $currency, bool $fa): string
    {
        $percent = (int) round(abs((float) ($data['change_percent'] ?? 0)));
        $down = ((float) ($data['change_percent'] ?? 0)) < 0;
        $current = $this->money((int) ($data['current'] ?? 0), $currency);

        return $fa
            ? sprintf('این دوره %d%% %s خرج کرده‌اید (%s).', $percent, $down ? 'کمتر' : 'بیشتر', $current)
            : sprintf('You spent %d%% %s this period (%s).', $percent, $down ? 'less' : 'more', $current);
    }

    private function money(int $minorUnits, string $code): string
    {
        return Currency::isSupported($code)
            ? (string) Money::of($minorUnits, $code)
            : $code.' '.$minorUnits;
    }

    private function locale(): string
    {
        return $this->context->get()?->locale ?? 'en';
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(array $payload): AiResponse
    {
        return new AiResponse(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            [],
            $this->name(),
        );
    }

    /** Cheap "is this bytes or is this writing" test — no finfo dependency. */
    private function looksLikeText(string $contents): bool
    {
        if ($contents === '' || str_contains($contents, "\0")) {
            return false;
        }

        return mb_check_encoding($contents, 'UTF-8')
            && preg_match('/[\p{L}\p{N}]/u', $contents) === 1;
    }
}
