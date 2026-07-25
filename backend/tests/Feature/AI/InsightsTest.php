<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Modules\AI\Actions\ForecastCashflow;
use Modules\AI\Actions\GenerateInsights;
use Modules\AI\Models\AiInsight;
use Modules\Banking\Models\Check;
use Modules\Core\Models\Workspace;
use PHPUnit\Framework\Attributes\Test;

/**
 * Insights are arithmetic (docs/08-ai-layer.md §6), so they are tested as
 * arithmetic: exact figures, and an outlier detector that has to stay quiet on
 * ordinary variation to be worth anything at all.
 */
final class InsightsTest extends AiTestCase
{
    #[Test]
    public function the_z_score_fires_on_a_genuine_outlier(): void
    {
        [, $workspace] = $this->world('anomaly@example.test');
        $account = $this->wallet($workspace);
        $restaurant = $this->categoryNamed($workspace, 'restaurant');

        // Six ordinary dinners, then one that is nothing like them.
        foreach ([100_00, 90_00, 110_00, 85_00, 105_00, 95_00] as $index => $amount) {
            $this->spend($workspace, $account, $restaurant, $amount, '2026-07-0'.($index + 1).' 20:00:00');
        }

        $this->spend($workspace, $account, $restaurant, 950_00, '2026-07-08 20:00:00');

        $anomalies = $this->insightsOfType($workspace, AiInsight::TYPE_ANOMALY);

        $this->assertCount(1, $anomalies);

        $data = $anomalies[0]->data;
        $this->assertSame($restaurant->id, $data['category_id']);
        $this->assertSame(950_00, $data['amount']);
        $this->assertSame(6, $data['baseline_count']);
        $this->assertSame(9750.0, (float) $data['baseline_mean'], 'The outlier must be excluded from its own baseline.');
        $this->assertGreaterThan(3.0, $data['z_score']);
        $this->assertSame(AiInsight::SEVERITY_WARNING, $anomalies[0]->severity);
        $this->assertNotSame('', trim((string) $anomalies[0]->body));
    }

    #[Test]
    public function the_z_score_stays_quiet_on_ordinary_variance(): void
    {
        [, $workspace] = $this->world('variance@example.test');
        $account = $this->wallet($workspace);
        $taxi = $this->categoryNamed($workspace, 'taxi');

        // Spread of roughly ±20%: normal life, not an anomaly.
        foreach ([100_00, 90_00, 110_00, 85_00, 105_00, 120_00] as $index => $amount) {
            $this->spend($workspace, $account, $taxi, $amount, '2026-07-0'.($index + 1).' 08:00:00');
        }

        $this->assertSame([], $this->insightsOfType($workspace, AiInsight::TYPE_ANOMALY));
    }

    #[Test]
    public function a_category_with_too_little_history_is_not_judged(): void
    {
        [, $workspace] = $this->world('sparse@example.test');
        $account = $this->wallet($workspace);
        $taxi = $this->categoryNamed($workspace, 'taxi');

        $this->spend($workspace, $account, $taxi, 10_00, '2026-07-01 08:00:00');
        $this->spend($workspace, $account, $taxi, 900_00, '2026-07-02 08:00:00');

        $this->assertSame([], $this->insightsOfType($workspace, AiInsight::TYPE_ANOMALY));
    }

    #[Test]
    public function spending_composition_is_computed_from_the_totals(): void
    {
        [, $workspace] = $this->world('composition@example.test');
        $account = $this->wallet($workspace);

        $this->spend($workspace, $account, $this->categoryNamed($workspace, 'restaurant'), 800_00, '2026-07-03 12:00:00');
        $this->spend($workspace, $account, $this->categoryNamed($workspace, 'taxi'), 200_00, '2026-07-04 12:00:00');

        $insight = $this->insightsOfType($workspace, AiInsight::TYPE_COMPOSITION)[0];

        $this->assertSame('home', $insight->data['category'], 'Restaurant rolls up to its root.');
        $this->assertSame(0.8, (float) $insight->data['share']);
        $this->assertSame(800_00, $insight->data['amount']);
        $this->assertSame(1000_00, $insight->data['total']);
        $this->assertStringContainsString('80', (string) $insight->body);
    }

    #[Test]
    public function period_over_period_change_is_exact(): void
    {
        [, $workspace] = $this->world('period@example.test');
        $account = $this->wallet($workspace);
        $category = $this->categoryNamed($workspace, 'restaurant');

        $this->spend($workspace, $account, $category, 200_00, '2026-06-10 12:00:00');
        $this->spend($workspace, $account, $category, 150_00, '2026-07-10 12:00:00');

        $insight = $this->insightsOfType($workspace, AiInsight::TYPE_PERIOD_CHANGE)[0];

        $this->assertSame(150_00, $insight->data['current']);
        $this->assertSame(200_00, $insight->data['previous']);
        $this->assertSame(-50_00, $insight->data['difference']);
        $this->assertSame(-25.0, (float) $insight->data['change_percent']);
    }

    #[Test]
    public function regenerating_updates_the_same_insight_rather_than_duplicating_it(): void
    {
        [, $workspace] = $this->world('idempotent@example.test');
        $account = $this->wallet($workspace);

        $this->spend($workspace, $account, $this->categoryNamed($workspace, 'restaurant'), 500_00, '2026-07-03 12:00:00');

        $this->inWorkspace($workspace, fn () => app(GenerateInsights::class)->handle());
        $this->inWorkspace($workspace, fn () => app(GenerateInsights::class)->handle());

        $this->assertSame(
            1,
            $this->inWorkspace($workspace, fn () => AiInsight::query()->ofType(AiInsight::TYPE_COMPOSITION)->count()),
        );
    }

    #[Test]
    public function the_cash_flow_forecast_arithmetic_is_exact(): void
    {
        [, $workspace] = $this->world('forecast@example.test');
        $account = $this->makeAccount($workspace, 'Main', 'TRY', 1_000_00);

        $this->earn($workspace, $account, 300_00, '2026-07-10 09:00:00');
        $this->spend($workspace, $account, null, 100_00, '2026-07-05 09:00:00');
        $this->spend($workspace, $account, null, 50_00, '2026-07-20 09:00:00');

        $this->inWorkspace($workspace, fn () => Check::query()->create([
            'account_id' => $account->id,
            'direction' => Check::DIRECTION_ISSUED,
            'check_number' => '4410',
            'amount' => 200_00,
            'currency' => 'TRY',
            'base_amount' => 200_00,
            'due_date' => '2026-08-04',
            'status' => Check::STATUS_ISSUED,
        ]));

        $forecast = $this->inWorkspace($workspace, fn () => app(ForecastCashflow::class)->handle(30));

        $this->assertSame('TRY', $forecast['currency']);
        $this->assertSame(30, $forecast['lookback_days']);
        $this->assertSame(300_00, $forecast['observed_income']);
        $this->assertSame(150_00, $forecast['observed_expense']);
        $this->assertSame(150_00, $forecast['observed_net']);

        // 15000 minor units over 30 days, truncated to the unit.
        $this->assertSame(500, $forecast['daily_net']);
        $this->assertSame(150_00, $forecast['projected_net']);

        // Opening 1000.00, plus 300.00 in, less 150.00 out. The seeded wallet
        // is empty and contributes nothing.
        $this->assertSame(1_150_00, $forecast['starting_balance']);
        $this->assertSame(200_00, $forecast['obligations']);

        $this->assertSame(
            $forecast['starting_balance'] + $forecast['projected_net'] - $forecast['obligations'],
            $forecast['projected_balance'],
        );
        $this->assertSame(1_100_00, $forecast['projected_balance']);
        $this->assertSame('2026-08-24', $forecast['to']);
    }

    /** @return list<AiInsight> */
    private function insightsOfType(Workspace $workspace, string $type): array
    {
        $insights = $this->inWorkspace($workspace, fn () => app(GenerateInsights::class)->handle());

        return array_values(array_filter($insights, static fn (AiInsight $insight) => $insight->type === $type));
    }
}
