<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Modules\AI\Actions\AnswerQuestion;
use Modules\AI\Tools\ToolRegistry;
use Modules\Core\Models\Workspace;
use Modules\Investment\Actions\RecordInvestmentTrade;
use Modules\Investment\Models\Investment;
use Modules\Investment\Models\InvestmentTransaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * «کدام سرمایه سود بیشتری داده؟» — docs/08-ai-layer.md §5.
 *
 * The figures are checked against arithmetic done by hand in the comments,
 * because the point of the tool is that a user can hold it against their
 * broker statement. Realised and unrealised are checked separately for the
 * same reason: a position that was sold at a profit and has since fallen must
 * not be able to present itself as a winner.
 */
final class InvestmentPerformanceToolTest extends AiTestCase
{
    #[Test]
    public function it_reports_roi_and_splits_realised_from_unrealised_profit(): void
    {
        [, $workspace] = $this->world('portfolio@example.test');

        // 10 units at ₺100 = ₺1,000 in. Priced at ₺150 today, so the ten units
        // held are worth ₺1,500: ₺500 unrealised, nothing realised, ROI 50%.
        $gold = $this->position($workspace, 'Gold bars', 'gold');
        $this->trade($workspace, $gold, ['action' => 'buy', 'quantity' => '10', 'price' => 100_00]);
        $this->price($workspace, $gold, 150_00);

        $result = $this->perform($workspace);

        $this->assertSame(1, $result['count']);

        $position = $result['positions'][0];
        $this->assertSame('Gold bars', $position['name']);
        $this->assertSame(1_000_00, $position['cost_basis']);
        $this->assertSame(1_500_00, $position['current_value']);
        $this->assertSame(500_00, $position['unrealized_profit']);
        $this->assertSame(0, $position['realized_profit']);
        $this->assertSame(500_00, $position['total_profit']);
        $this->assertSame(50.0, $position['roi_percent']);

        $this->assertSame(1_000_00, $result['portfolio']['cost_basis']);
        $this->assertSame(500_00, $result['portfolio']['total_profit']);
        $this->assertSame(50.0, $result['portfolio']['roi_percent']);
    }

    #[Test]
    public function a_part_sold_position_reports_the_profit_it_banked_and_the_profit_it_still_holds(): void
    {
        [, $workspace] = $this->world('halfsold@example.test');

        $stock = $this->position($workspace, 'Acme', 'stock');

        // 20 at ₺50 = ₺1,000. Sell 8 at ₺80: (80 − 50) × 8 = ₺240 realised.
        // 12 units still held cost ₺600; priced at ₺60 they are worth ₺720, so
        // ₺120 unrealised. Total ₺360 against a ₺600 basis — 60%.
        $this->trade($workspace, $stock, ['action' => 'buy', 'quantity' => '20', 'price' => 50_00]);
        $this->trade($workspace, $stock, ['action' => 'sell', 'quantity' => '8', 'price' => 80_00]);
        $this->price($workspace, $stock, 60_00);

        $position = $this->perform($workspace)['positions'][0];

        $this->assertSame(240_00, $position['realized_profit']);
        $this->assertSame(120_00, $position['unrealized_profit']);
        $this->assertSame(360_00, $position['total_profit']);
        $this->assertSame(600_00, $position['cost_basis']);
        $this->assertSame(60.0, $position['roi_percent']);
    }

    #[Test]
    public function positions_come_back_best_first_and_the_portfolio_totals_in_the_base_currency(): void
    {
        [, $workspace] = $this->world('ranked@example.test');

        $gold = $this->position($workspace, 'Gold bars', 'gold');
        $this->trade($workspace, $gold, ['action' => 'buy', 'quantity' => '10', 'price' => 100_00]);
        $this->price($workspace, $gold, 150_00); // +₺500

        $stock = $this->position($workspace, 'Acme', 'stock');
        $this->trade($workspace, $stock, ['action' => 'buy', 'quantity' => '10', 'price' => 100_00]);
        $this->price($workspace, $stock, 110_00); // +₺100

        $loser = $this->position($workspace, 'Beta', 'stock');
        $this->trade($workspace, $loser, ['action' => 'buy', 'quantity' => '10', 'price' => 100_00]);
        $this->price($workspace, $loser, 90_00); // −₺100

        $result = $this->perform($workspace);

        $this->assertSame(['Gold bars', 'Acme', 'Beta'], array_column($result['positions'], 'name'));
        $this->assertSame('Gold bars', $result['best']);
        $this->assertSame('TRY', $result['currency']);

        // ₺3,000 in, ₺3,500 out: +₺500 across the three.
        $this->assertSame(3_000_00, $result['portfolio']['cost_basis']);
        $this->assertSame(3_500_00, $result['portfolio']['current_value']);
        $this->assertSame(500_00, $result['portfolio']['unrealized_profit']);
    }

    #[Test]
    public function a_position_in_another_currency_is_converted_into_the_base_currency(): void
    {
        [, $workspace] = $this->world('multicurrency@example.test');

        $dollars = $this->position($workspace, 'US shares', 'stock', 'USD');
        $this->trade($workspace, $dollars, ['action' => 'buy', 'quantity' => '10', 'price' => 100_00]);
        $this->price($workspace, $dollars, 120_00);

        $position = $this->perform($workspace)['positions'][0];

        // Quoted in its own currency…
        $this->assertSame('USD', $position['currency']);
        $this->assertSame(200_00, $position['unrealized_profit']);

        // …and again in the workspace's, which is the only figure a portfolio
        // total may be built from.
        $this->assertSame('TRY', $position['base_currency']);
        $this->assertGreaterThan(200_00, $position['unrealized_profit_in_base']);
        $this->assertSame(
            $position['unrealized_profit_in_base'],
            $this->perform($workspace)['portfolio']['unrealized_profit'],
        );
    }

    #[Test]
    public function the_kind_argument_narrows_the_asset_class(): void
    {
        [, $workspace] = $this->world('kinds@example.test');

        $gold = $this->position($workspace, 'Gold bars', 'gold');
        $this->trade($workspace, $gold, ['action' => 'buy', 'quantity' => '1', 'price' => 100_00]);

        $stock = $this->position($workspace, 'Acme', 'stock');
        $this->trade($workspace, $stock, ['action' => 'buy', 'quantity' => '1', 'price' => 100_00]);

        $this->assertSame(['Gold bars'], array_column($this->perform($workspace, ['kind' => 'gold'])['positions'], 'name'));
        $this->assertSame(2, $this->perform($workspace)['count']);
    }

    #[Test]
    public function an_empty_portfolio_answers_zero_rather_than_dividing_by_it(): void
    {
        [, $workspace] = $this->world('empty@example.test');

        $result = $this->perform($workspace);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['positions']);
        $this->assertSame(0.0, $result['portfolio']['roi_percent']);
        $this->assertNull($result['best']);
    }

    #[Test]
    public function it_never_returns_another_workspaces_positions(): void
    {
        [$mine, $theirs] = $this->twoPortfolios();

        $result = $this->perform($mine);

        $this->assertSame(['ALPHAGOLD'], array_column($result['positions'], 'name'));

        $encoded = (string) json_encode($result, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('BETAGOLD', $encoded);
        $this->assertStringNotContainsString($theirs->id, $encoded);
    }

    #[Test]
    public function a_workspace_argument_is_dropped_before_the_tool_runs(): void
    {
        [$mine, $theirs] = $this->twoPortfolios();

        $outcome = $this->inWorkspace($mine, fn () => app(ToolRegistry::class)->execute('get_investment_performance', [
            'workspace_id' => $theirs->id,
            'workspace' => $theirs->id,
            'limit' => 5,
        ]));

        $this->assertEqualsCanonicalizing(['workspace_id', 'workspace'], $outcome['dropped_arguments']);
        $this->assertSame(['limit' => 5], $outcome['arguments']);
        $this->assertSame(['ALPHAGOLD'], array_column($outcome['result']['positions'], 'name'));
    }

    #[Test]
    public function the_chat_reaches_the_tool_with_no_network(): void
    {
        [, $workspace] = $this->world('chat-investments@example.test');

        $gold = $this->position($workspace, 'Gold bars', 'gold');
        $this->trade($workspace, $gold, ['action' => 'buy', 'quantity' => '10', 'price' => 100_00]);
        $this->price($workspace, $gold, 150_00);

        $answer = $this->inWorkspace(
            $workspace,
            fn () => app(AnswerQuestion::class)->handle('کدام سرمایه سود بیشتری داده؟'),
        );

        $this->assertSame(['get_investment_performance'], $answer['tool_calls']);
        $this->assertSame('deterministic', $answer['provider']);
        $this->assertStringContainsString('Gold bars', $answer['answer']);
        $this->assertSame([], $answer['redacted']);
    }

    #[Test]
    public function the_english_phrasing_reaches_it_too(): void
    {
        [, $workspace] = $this->world('chat-roi@example.test');

        $answer = $this->inWorkspace(
            $workspace,
            fn () => app(AnswerQuestion::class)->handle('how is my investment portfolio doing?'),
        );

        $this->assertContains('get_investment_performance', $answer['tool_calls']);
    }

    /** @return array{Workspace, Workspace} */
    private function twoPortfolios(): array
    {
        [, $mine] = $this->world('alpha-invest@example.test');
        [, $theirs] = $this->world('beta-invest@example.test');

        $this->trade($mine, $this->position($mine, 'ALPHAGOLD', 'gold'), [
            'action' => 'buy', 'quantity' => '1', 'price' => 100_00,
        ]);

        $this->trade($theirs, $this->position($theirs, 'BETAGOLD', 'gold'), [
            'action' => 'buy', 'quantity' => '1', 'price' => 999_00,
        ]);

        return [$mine, $theirs];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function perform(Workspace $workspace, array $arguments = []): array
    {
        return $this->inWorkspace(
            $workspace,
            fn () => app(ToolRegistry::class)->execute('get_investment_performance', $arguments)['result'],
        );
    }

    private function position(
        Workspace $workspace,
        string $name,
        string $kind,
        string $currency = 'TRY',
    ): Investment {
        return $this->inWorkspace($workspace, fn () => Investment::query()->create([
            'name' => $name,
            'kind' => $kind,
            'currency' => $currency,
            'quantity' => '0',
            'avg_buy_price' => 0,
        ]));
    }

    private function price(Workspace $workspace, Investment $investment, int $price): void
    {
        $this->inWorkspace($workspace, function () use ($investment, $price): void {
            $investment->forceFill(['current_price' => $price, 'priced_at' => $this->now()])->save();
        });
    }

    /** @param array{action: string, quantity: string, price: int} $data */
    private function trade(Workspace $workspace, Investment $investment, array $data): InvestmentTransaction
    {
        return $this->inWorkspace($workspace, fn () => app(RecordInvestmentTrade::class)->handle(
            $data + ['investment_id' => $investment->id],
        ));
    }
}
