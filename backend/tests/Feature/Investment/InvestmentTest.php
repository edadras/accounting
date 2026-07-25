<?php

declare(strict_types=1);

namespace Tests\Feature\Investment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Investment\Actions\RecordInvestmentTrade;
use Modules\Investment\Exceptions\InvestmentException;
use Modules\Investment\Models\Investment;
use Modules\Investment\Models\InvestmentTransaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * The invariants of a position. Weighted-average cost and realised profit are
 * the two numbers a user checks against their broker statement — if either
 * drifts by a minor unit the whole module is untrustworthy.
 */
final class InvestmentTest extends LedgerTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_weighted_average_cost_blends_every_buy_at_its_own_price(): void
    {
        $user = $this->makeUser('trader@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        // 10 units at ₺100, then 5 at ₺160: (1,000 + 800) / 15 = ₺120.
        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 10000]);
        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '5', 'price' => 16000]);

        $investment->refresh();

        $this->assertSame('15.00000000', (string) $investment->quantity);
        $this->assertSame(12000, $investment->avg_buy_price);

        // A third lot at ₺60 pulls it to (1,800 + 300) / 20 = ₺105.
        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '5', 'price' => 6000]);

        $investment->refresh();

        $this->assertSame('20.00000000', (string) $investment->quantity);
        $this->assertSame(10500, $investment->avg_buy_price);
        $this->assertSame(210000, $investment->costBasis()->minorUnits);
    }

    #[Test]
    public function fractional_quantities_average_exactly_too(): void
    {
        $user = $this->makeUser('satoshi@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'USD');
        $investment = $this->makeInvestment($workspace, 'Bitcoin', 'crypto', 'USD');

        // 0.5 BTC at $64,000 and 1.5 at $48,000 average to $52,000.
        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '0.5', 'price' => 6_400_000]);
        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '1.5', 'price' => 4_800_000]);

        $investment->refresh();

        $this->assertSame('2.00000000', (string) $investment->quantity);
        $this->assertSame(5_200_000, $investment->avg_buy_price);
        $this->assertSame(10_400_000, $investment->costBasis()->minorUnits);
    }

    #[Test]
    public function a_sell_reduces_the_quantity_but_never_moves_the_average_cost(): void
    {
        $user = $this->makeUser('seller@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 10000]);
        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 11000]);

        $this->assertSame(10500, $investment->refresh()->avg_buy_price);

        $this->trade($workspace, $investment, ['action' => 'sell', 'quantity' => '8', 'price' => 20000]);

        $investment->refresh();

        // The units still held cost what they always cost.
        $this->assertSame(10500, $investment->avg_buy_price);
        $this->assertSame('12.00000000', (string) $investment->quantity);
    }

    #[Test]
    public function realized_profit_counts_the_fee_that_was_paid_to_take_it(): void
    {
        $user = $this->makeUser('fees@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 10000]);
        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 11000]);

        $trade = $this->trade($workspace, $investment, [
            'action' => 'sell',
            'quantity' => '8',
            'price' => 20000,
            'fee' => 500,
        ]);

        // (20000 − 10500) × 8 = 76,000 minor units, less the ₺5 fee.
        $this->assertSame(75500, $trade->realized_profit);
        $this->assertSame(75500, $investment->refresh()->realized_profit);
    }

    #[Test]
    public function selling_below_cost_realizes_a_negative_number(): void
    {
        $user = $this->makeUser('loss@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 10000]);

        $trade = $this->trade($workspace, $investment, [
            'action' => 'sell',
            'quantity' => '4',
            'price' => 7000,
            'fee' => 250,
        ]);

        // (7000 − 10000) × 4 = −12,000, and the fee makes the loss deeper.
        $this->assertSame(-12250, $trade->realized_profit);
        $this->assertSame(-12250, $investment->refresh()->realized_profit);
        $this->assertTrue($investment->realizedProfit()->isNegative());
    }

    #[Test]
    public function realized_profit_accumulates_across_sells(): void
    {
        $user = $this->makeUser('running@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 10000]);
        $this->trade($workspace, $investment, ['action' => 'sell', 'quantity' => '2', 'price' => 15000]);
        $this->trade($workspace, $investment, ['action' => 'sell', 'quantity' => '3', 'price' => 8000]);

        // +10,000 then −6,000.
        $this->assertSame(4000, $investment->refresh()->realized_profit);
        $this->assertSame('5.00000000', (string) $investment->quantity);
    }

    #[Test]
    public function selling_more_than_is_held_is_refused(): void
    {
        $user = $this->makeUser('oversell@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 10000]);

        try {
            $this->trade($workspace, $investment, ['action' => 'sell', 'quantity' => '10.00000001', 'price' => 12000]);
            $this->fail('Overselling should have been refused.');
        } catch (InvestmentException $e) {
            $this->assertSame('insufficient_quantity', $e->errorCode);
        }

        // The refusal must leave the position exactly as it was.
        $investment->refresh();
        $this->assertSame('10.00000000', (string) $investment->quantity);
        $this->assertSame(0, $investment->realized_profit);
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => InvestmentTransaction::query()->count()));
    }

    #[Test]
    public function unrealized_profit_and_roi_follow_the_current_price(): void
    {
        $user = $this->makeUser('roi@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 10000]);

        $this->inWorkspace($workspace, fn () => $investment->refresh()->update(['current_price' => 12500]));

        $investment->refresh();

        $this->assertSame(100000, $investment->costBasis()->minorUnits);
        $this->assertSame(125000, $investment->currentValue()->minorUnits);
        $this->assertSame(25000, $investment->unrealizedProfit()->minorUnits);
        $this->assertSame(25.0, $investment->roi());
    }

    #[Test]
    public function roi_counts_profit_already_banked_as_well_as_profit_on_paper(): void
    {
        $user = $this->makeUser('banked@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '10', 'price' => 10000]);
        $this->trade($workspace, $investment, ['action' => 'sell', 'quantity' => '2', 'price' => 15000]);

        $this->inWorkspace($workspace, fn () => $investment->refresh()->update(['current_price' => 12500]));

        $investment->refresh();

        // 8 units cost ₺800 and are worth ₺1,000; ₺100 was already banked.
        $this->assertSame(10000, $investment->realized_profit);
        $this->assertSame(80000, $investment->costBasis()->minorUnits);
        $this->assertSame(20000, $investment->unrealizedProfit()->minorUnits);
        $this->assertSame(30000, $investment->totalProfit()->minorUnits);
        $this->assertSame(37.5, $investment->roi());
    }

    #[Test]
    public function a_price_that_has_never_been_set_reports_no_paper_profit(): void
    {
        $user = $this->makeUser('unpriced@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '3', 'price' => 10000]);

        $investment->refresh();

        $this->assertNull($investment->currentPrice());
        $this->assertSame(0, $investment->unrealizedProfit()->minorUnits);
        $this->assertSame(0.0, $investment->roi());
    }

    #[Test]
    public function a_dividend_banks_profit_without_touching_the_position(): void
    {
        $user = $this->makeUser('dividend@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace, 'Index fund', 'etf');

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '100', 'price' => 5000]);
        $this->trade($workspace, $investment, ['action' => 'dividend', 'price' => 120, 'fee' => 200]);

        $investment->refresh();

        // Paid on all 100 units held: ₺120 less the ₺2 handling fee.
        $this->assertSame(11800, $investment->realized_profit);
        $this->assertSame('100.00000000', (string) $investment->quantity);
        $this->assertSame(5000, $investment->avg_buy_price);
    }

    #[Test]
    public function a_split_changes_the_slicing_but_not_the_cost_basis(): void
    {
        $user = $this->makeUser('split@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace, 'Split Co', 'stock');

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '30', 'price' => 9000]);

        $basisBefore = $investment->refresh()->costBasis()->minorUnits;

        $this->trade($workspace, $investment, ['action' => 'split', 'quantity' => '3']);

        $investment->refresh();

        $this->assertSame('90.00000000', (string) $investment->quantity);
        $this->assertSame(3000, $investment->avg_buy_price);
        $this->assertSame($basisBefore, $investment->costBasis()->minorUnits);
    }

    #[Test]
    public function the_trade_currency_must_be_the_positions_currency(): void
    {
        $user = $this->makeUser('mismatch-inv@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->expectException(InvestmentException::class);

        $this->trade($workspace, $investment, [
            'action' => 'buy',
            'quantity' => '1',
            'price' => 100,
            'currency' => 'USD',
        ]);
    }

    #[Test]
    public function replaying_the_same_idempotency_key_does_not_trade_twice(): void
    {
        $user = $this->makeUser('retry-inv@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $payload = [
            'action' => 'buy',
            'quantity' => '4',
            'price' => 10000,
            'idempotency_key' => 'retry-after-timeout',
        ];

        $first = $this->trade($workspace, $investment, $payload);
        $second = $this->trade($workspace, $investment, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('4.00000000', (string) $investment->refresh()->quantity);
    }

    #[Test]
    public function the_trade_endpoint_records_a_buy_and_performance_reports_it(): void
    {
        $user = $this->makeUser('http@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        Sanctum::actingAs($user);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $this->postJson("/api/v1/investments/{$investment->id}/trades", [
            'action' => 'buy',
            'quantity' => '10',
            'price' => 10000,
        ], $headers)->assertCreated();

        $this->postJson("/api/v1/investments/{$investment->id}/trades", [
            'action' => 'sell',
            'quantity' => '2',
            'price' => 15000,
        ], $headers)->assertCreated()->assertJsonPath('data.realized_profit.value', 10000);

        $this->inWorkspace($workspace, fn () => $investment->refresh()->update(['current_price' => 12500]));

        $this->getJson("/api/v1/investments/{$investment->id}/performance", $headers)
            ->assertOk()
            ->assertJsonPath('data.avg_buy_price.value', 10000)
            ->assertJsonPath('data.avg_buy_price.currency', 'TRY')
            ->assertJsonPath('data.avg_buy_price.minor_unit', 2)
            ->assertJsonPath('data.avg_buy_price.decimal', '100.00')
            ->assertJsonPath('data.unrealized_profit.value', 20000)
            ->assertJsonPath('data.realized_profit.value', 10000)
            ->assertJsonPath('data.roi', 37.5);
    }

    #[Test]
    public function the_trade_endpoint_refuses_an_oversell_with_a_stable_error_code(): void
    {
        $user = $this->makeUser('http-oversell@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $this->trade($workspace, $investment, ['action' => 'buy', 'quantity' => '1', 'price' => 10000]);

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/investments/{$investment->id}/trades", [
            'action' => 'sell',
            'quantity' => '2',
            'price' => 10000,
        ], ['X-Workspace-Id' => $workspace->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_quantity');
    }

    #[Test]
    public function a_viewer_may_read_a_position_but_not_trade_it(): void
    {
        $owner = $this->makeUser('inv-owner@example.test');
        $workspace = $this->makeWorkspace($owner, currency: 'TRY');
        $investment = $this->makeInvestment($workspace);

        $viewer = $this->makeUser('inv-viewer@example.test');
        $workspace->members()->create([
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($viewer);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $this->getJson('/api/v1/investments', $headers)->assertOk();

        $this->postJson("/api/v1/investments/{$investment->id}/trades", [
            'action' => 'buy',
            'quantity' => '1',
            'price' => 100,
        ], $headers)->assertForbidden();
    }

    #[Test]
    public function one_workspace_never_sees_another_workspaces_positions(): void
    {
        $victim = $this->makeUser('inv-victim@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim books', 'TRY');
        $victimInvestment = $this->makeInvestment($victimWorkspace, 'Secret gold');
        $this->trade($victimWorkspace, $victimInvestment, ['action' => 'buy', 'quantity' => '5', 'price' => 90000]);

        $intruder = $this->makeUser('inv-intruder@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder books', 'TRY');

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($intruder);

        // Someone else's workspace header: refused before any query runs.
        $this->getJson('/api/v1/investments', ['X-Workspace-Id' => $victimWorkspace->id])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'workspace_forbidden');

        // Their own header plus someone else's id: the global scope stops it.
        $this->getJson("/api/v1/investments/{$victimInvestment->id}", [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertNotFound();

        $this->postJson("/api/v1/investments/{$victimInvestment->id}/trades", [
            'action' => 'sell',
            'quantity' => '5',
            'price' => 100000,
        ], ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'investment_not_found');

        $this->getJson('/api/v1/investments', ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // And the victim's position is untouched by the attempt.
        $this->assertSame('5.00000000', (string) $victimInvestment->refresh()->quantity);

        // Fail closed: no active workspace must yield nothing, not everything.
        app(WorkspaceContext::class)->forget();
        $this->assertSame(0, Investment::query()->count());
        $this->assertSame(0, InvestmentTransaction::query()->count());
    }

    private function makeInvestment(
        Workspace $workspace,
        string $name = 'Gold bars',
        string $kind = 'gold',
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

    /** @param  array<string, mixed>  $data */
    private function trade(Workspace $workspace, Investment $investment, array $data): InvestmentTransaction
    {
        return $this->inWorkspace($workspace, fn () => app(RecordInvestmentTrade::class)->handle(
            $data + ['investment_id' => $investment->id],
        ));
    }
}
