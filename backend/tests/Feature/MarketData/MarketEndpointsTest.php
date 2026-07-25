<?php

declare(strict_types=1);

namespace Tests\Feature\MarketData;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Investment\Models\Investment;
use PHPUnit\Framework\Attributes\Test;

final class MarketEndpointsTest extends MarketDataTestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_rates_endpoint_returns_the_latest_rate_and_a_short_history(): void
    {
        $user = $this->makeUser('reader@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        config(['market.rates.endpoint' => 'https://rates.test/latest']);

        $this->fakeHttp(['*' => Http::response(['rates' => ['TRY' => '32.5']])]);
        $this->refreshRates(quotes: ['TRY']);

        $this->fakeHttp(['*' => Http::response(['rates' => ['TRY' => '33.0']])]);
        $this->refreshRates(quotes: ['TRY']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/market/rates?base=USD&quote=TRY', [
            'X-Workspace-Id' => $workspace->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.base', 'USD');
        $response->assertJsonPath('data.quote', 'TRY');
        $response->assertJsonCount(2, 'data.history');

        $this->assertSame(33.0, (float) $response->json('data.rate'));
        $this->assertSame('remote', $response->json('data.source'));
        $this->assertNotNull($response->json('data.rated_at'));
    }

    #[Test]
    public function the_rates_endpoint_defaults_the_quote_to_the_workspace_currency(): void
    {
        $user = $this->makeUser('default-quote@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'EUR');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/market/rates', ['X-Workspace-Id' => $workspace->id]);

        $response->assertOk();
        $response->assertJsonPath('data.quote', 'EUR');

        // Nothing stored yet, so the answer comes from the same fallback the
        // ledger uses rather than being absent.
        $response->assertJsonPath('data.source', 'fallback');
        $response->assertJsonCount(0, 'data.history');
    }

    #[Test]
    public function the_rates_endpoint_refuses_an_unknown_currency(): void
    {
        $user = $this->makeUser('bad-currency@example.test');
        $workspace = $this->makeWorkspace($user);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/market/rates?quote=ZZZ', ['X-Workspace-Id' => $workspace->id])
            ->assertStatus(422);
    }

    #[Test]
    public function the_prices_endpoint_exposes_only_the_callers_own_positions(): void
    {
        $mine = $this->makeUser('mine@example.test');
        $theirs = $this->makeUser('theirs@example.test');

        $workspaceA = $this->makeWorkspace($mine, 'Mine', 'USD');
        $workspaceB = $this->makeWorkspace($theirs, 'Theirs', 'USD');

        $this->makePosition($workspaceA, 'My Bitcoin');
        $this->makePosition($workspaceB, 'Their Bitcoin');

        config(['market.prices.endpoint' => 'https://prices.test/quotes']);
        $this->fakeHttp(['*' => Http::response(['prices' => [
            ['symbol' => 'BTC', 'price' => '64000.00', 'currency' => 'USD', 'kind' => 'crypto'],
        ]])]);

        $this->refreshPrices();

        Sanctum::actingAs($mine);

        $response = $this->getJson('/api/v1/market/prices/BTC', ['X-Workspace-Id' => $workspaceA->id]);

        $response->assertOk();
        $response->assertJsonPath('data.symbol', 'BTC');
        $response->assertJsonPath('data.currency', 'USD');
        $this->assertSame(64000.0, (float) $response->json('data.price'));

        // The series is shared market data; the holdings beside it are not.
        $response->assertJsonCount(1, 'data.positions');
        $response->assertJsonPath('data.positions.0.name', 'My Bitcoin');

        $this->getJson('/api/v1/market/prices/BTC', ['X-Workspace-Id' => $workspaceB->id])
            ->assertStatus(403);
    }

    #[Test]
    public function an_unknown_symbol_is_a_404_and_the_endpoints_need_a_workspace(): void
    {
        $user = $this->makeUser('nothing@example.test');
        $workspace = $this->makeWorkspace($user);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/market/prices/NOPE', ['X-Workspace-Id' => $workspace->id])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');

        $this->getJson('/api/v1/market/prices/BTC')->assertStatus(400);
        $this->getJson('/api/v1/market/rates')->assertStatus(400);
    }

    #[Test]
    public function the_endpoints_are_closed_to_anonymous_callers(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('anon@example.test'));

        $this->getJson('/api/v1/market/rates', ['X-Workspace-Id' => $workspace->id])->assertStatus(401);
        $this->getJson('/api/v1/market/prices/BTC', ['X-Workspace-Id' => $workspace->id])->assertStatus(401);
    }

    private function makePosition(Workspace $workspace, string $name): Investment
    {
        return $this->inWorkspace($workspace, fn () => Investment::query()->create([
            'name' => $name,
            'kind' => 'crypto',
            'symbol' => 'BTC',
            'currency' => 'USD',
            'quantity' => '1.00000000',
            'avg_buy_price' => 5_000_000,
        ]));
    }
}
