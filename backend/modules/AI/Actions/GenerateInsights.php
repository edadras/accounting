<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Contracts\AiProvider;
use Modules\AI\Models\AiInsight;
use Modules\AI\Support\AiSwitch;
use Modules\AI\Support\PromptBuilder;
use Modules\AI\Tools\GetSpendingSummary;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;

/**
 * The smart dashboard, computed with statistics.
 *
 * docs/08-ai-layer.md §6 is emphatic and correct: most insights are aggregation,
 * not inference. Composition, period-over-period change, an outlier against a
 * category's own history and a cash-flow projection are all arithmetic, and
 * arithmetic is cheaper, faster, reproducible, and — unlike a model — cannot
 * assert something about the user's money that is simply untrue.
 *
 * The model is given one job at the end: turning the numbers into a sentence.
 * Every insight stores the figures it was derived from, so the sentence can
 * always be audited against them.
 */
final readonly class GenerateInsights
{
    public function __construct(
        private WorkspaceContext $context,
        private AiProvider $provider,
        private GetSpendingSummary $summary,
        private ForecastCashflow $forecast,
    ) {}

    /**
     * @param  array{now?: CarbonImmutable}  $options
     * @return list<AiInsight>
     */
    public function handle(array $options = []): array
    {
        $workspace = $this->context->require();
        AiSwitch::assertEnabled($workspace);

        $now = ($options['now'] ?? CarbonImmutable::now())->startOfDay();
        $insights = [];

        foreach ([
            $this->composition($now),
            $this->periodChange($now),
            $this->cashflow($now),
            ...$this->anomalies($now),
        ] as $candidate) {
            if ($candidate !== null) {
                $insights[] = $this->store($candidate, $now);
            }
        }

        return $insights;
    }

    /**
     * «۸۲٪ هزینه‌های شما مربوط به غذاست.»
     *
     * @return array<string, mixed>|null
     */
    private function composition(CarbonImmutable $now): ?array
    {
        $result = $this->summary->run([
            'from' => $now->startOfMonth()->toDateString(),
            'to' => $now->endOfMonth()->toDateString(),
            'group_by' => 'category',
        ]);

        $top = $result['groups'][0] ?? null;

        if ($top === null || (int) $result['total'] <= 0) {
            return null;
        }

        $share = (float) $top['share'];

        if ($share < (float) config('ai.insights.composition_min_share', 0.15)) {
            return null;
        }

        return [
            'type' => AiInsight::TYPE_COMPOSITION,
            'severity' => AiInsight::SEVERITY_INFO,
            'fingerprint' => 'composition:'.$now->format('Y-m'),
            'score' => $share,
            'period' => [$now->startOfMonth(), $now->endOfMonth()],
            'data' => [
                'category' => $top['label'],
                'share' => $share,
                'amount' => (int) $top['amount'],
                'total' => (int) $result['total'],
                'transaction_count' => (int) $result['transaction_count'],
                'currency' => (string) $result['currency'],
                'from' => (string) $result['from'],
                'to' => (string) $result['to'],
            ],
        ];
    }

    /**
     * «این ماه ۱۵٪ کمتر خرج کرده‌اید.»
     *
     * @return array<string, mixed>|null
     */
    private function periodChange(CarbonImmutable $now): ?array
    {
        $previousMonth = $now->subMonthNoOverflow();

        $current = $this->summary->run([
            'from' => $now->startOfMonth()->toDateString(),
            'to' => $now->endOfMonth()->toDateString(),
        ]);

        $previous = $this->summary->run([
            'from' => $previousMonth->startOfMonth()->toDateString(),
            'to' => $previousMonth->endOfMonth()->toDateString(),
        ]);

        $before = (int) $previous['total'];

        if ($before <= 0) {
            // No baseline: "up ∞%" is not an insight, it is a division by zero.
            return null;
        }

        $after = (int) $current['total'];
        $changePercent = round((($after - $before) / $before) * 100, 2);

        return [
            'type' => AiInsight::TYPE_PERIOD_CHANGE,
            'severity' => $changePercent > 25.0 ? AiInsight::SEVERITY_WARNING : AiInsight::SEVERITY_INFO,
            'fingerprint' => 'period_change:'.$now->format('Y-m'),
            'score' => abs($changePercent),
            'period' => [$now->startOfMonth(), $now->endOfMonth()],
            'data' => [
                'current' => $after,
                'previous' => $before,
                'difference' => $after - $before,
                'change_percent' => $changePercent,
                'currency' => (string) $current['currency'],
                'from' => (string) $current['from'],
                'to' => (string) $current['to'],
                'previous_from' => (string) $previous['from'],
                'previous_to' => (string) $previous['to'],
            ],
        ];
    }

    /**
     * An unusually large expense, judged against that category's own history.
     *
     * The baseline deliberately excludes the transaction being judged. Include
     * it and a single large outlier inflates the standard deviation it is
     * measured against until it stops looking unusual — the exact case this is
     * meant to catch.
     *
     * @return list<array<string, mixed>>
     */
    private function anomalies(CarbonImmutable $now): array
    {
        $lookback = max(1, (int) config('ai.insights.lookback_days', 90));
        $threshold = (float) config('ai.insights.anomaly_z_threshold', 3.0);
        $minSamples = max(2, (int) config('ai.insights.anomaly_min_samples', 5));

        $rows = Transaction::query()
            ->ofType(Transaction::TYPE_EXPENSE)
            ->between($now->subDays($lookback), $now->endOfDay())
            ->where('base_currency', $this->context->baseCurrency())
            ->whereNotNull('category_id')
            ->get(['id', 'category_id', 'base_amount', 'occurred_at', 'description']);

        $names = Category::query()->pluck('name', 'id');
        $insights = [];

        foreach ($rows->groupBy('category_id') as $categoryId => $group) {
            if ($group->count() < $minSamples + 1) {
                continue;
            }

            $candidate = $group->sortByDesc('base_amount')->first();

            if ($candidate === null) {
                continue;
            }

            $baseline = $group->reject(fn (Transaction $row) => $row->id === $candidate->id)
                ->pluck('base_amount')
                ->map(static fn (mixed $value) => (int) $value)
                ->all();

            $mean = array_sum($baseline) / count($baseline);
            $variance = 0.0;

            foreach ($baseline as $value) {
                $variance += ($value - $mean) ** 2;
            }

            $stdDev = sqrt($variance / count($baseline));
            $amount = (int) $candidate->base_amount;

            if ($amount <= $mean) {
                continue;
            }

            // Every prior charge identical: any departure is infinitely many
            // standard deviations out, which the score would rather not be.
            $z = $stdDev > 0.0 ? ($amount - $mean) / $stdDev : INF;

            if ($z < $threshold) {
                continue;
            }

            $insights[] = [
                'type' => AiInsight::TYPE_ANOMALY,
                'severity' => AiInsight::SEVERITY_WARNING,
                'fingerprint' => 'anomaly:'.$candidate->id,
                'score' => is_finite($z) ? round($z, 3) : 999.0,
                'period' => [$now->subDays($lookback), $now],
                'data' => [
                    'category_id' => (string) $categoryId,
                    'category' => (string) ($names[$categoryId] ?? '?'),
                    'transaction_id' => (string) $candidate->id,
                    'occurred_at' => $candidate->occurred_at->toDateString(),
                    'amount' => $amount,
                    'baseline_mean' => round($mean, 2),
                    'baseline_stddev' => round($stdDev, 2),
                    'baseline_count' => count($baseline),
                    'z_score' => is_finite($z) ? round($z, 3) : null,
                    'threshold' => $threshold,
                    'currency' => $this->context->baseCurrency(),
                    'lookback_days' => $lookback,
                ],
            ];
        }

        return $insights;
    }

    /**
     * «اگر همین روند ادامه پیدا کند تا پایان ماه ۲۵۰ دلار کمبود خواهید داشت.»
     *
     * @return array<string, mixed>|null
     */
    private function cashflow(CarbonImmutable $now): ?array
    {
        $days = (int) $now->diffInDays($now->endOfMonth(), absolute: true);

        if ($days < 1) {
            return null;
        }

        $forecast = $this->forecast->handle($days, $now);

        return [
            'type' => AiInsight::TYPE_FORECAST,
            'severity' => $forecast['projected_balance'] < 0
                ? AiInsight::SEVERITY_WARNING
                : AiInsight::SEVERITY_INFO,
            'fingerprint' => 'forecast:'.$now->format('Y-m'),
            'score' => (float) $forecast['projected_balance'],
            'period' => [$now, $now->endOfMonth()],
            'data' => $forecast,
        ];
    }

    /**
     * Persists one finding, asking the provider only to phrase it.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function store(array $candidate, CarbonImmutable $now): AiInsight
    {
        $body = trim($this->provider->complete(
            PromptBuilder::system(PromptBuilder::TASK_PHRASE_INSIGHT, [
                'Restate the figures in one or two plain sentences. Do not compute anything new.',
                'Never introduce a number that is not in the data block.',
            ]),
            PromptBuilder::make()->contextJson('insight', [
                'type' => $candidate['type'],
                'data' => $candidate['data'],
            ])->toString(),
        )->content);

        /** @var array{0: CarbonImmutable, 1: CarbonImmutable} $period */
        $period = $candidate['period'];

        $insight = AiInsight::query()->firstOrNew([
            'fingerprint' => (string) $candidate['fingerprint'],
        ]);

        $insight->fill([
            'type' => (string) $candidate['type'],
            'severity' => (string) $candidate['severity'],
            'title' => (string) $candidate['type'],
            'body' => $body,
            'data' => $candidate['data'],
            'period_start' => $period[0]->toDateString(),
            'period_end' => $period[1]->toDateString(),
            'score' => (float) $candidate['score'],
            'computed_at' => $now,
        ])->save();

        return $insight;
    }
}
