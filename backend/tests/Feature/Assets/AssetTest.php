<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Core\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Assets\Actions\CalculateDepreciation;
use Modules\Assets\Exceptions\AssetException;
use Modules\Assets\Models\Asset;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * The invariants of a depreciation schedule: it must land exactly on the
 * salvage value, never below it, and never lose a minor unit on the way. A
 * schedule that is a few units out is a schedule nobody can reconcile.
 */
final class AssetTest extends LedgerTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_linear_schedule_sums_to_exactly_the_depreciable_base(): void
    {
        $user = $this->makeUser('linear@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        // ₺10,000 down to a ₺1,000 salvage over 7 years: 9,000 / 7 does not
        // divide, which is precisely the case that loses units when done naively.
        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 1_000_000,
            'salvage_value' => 100_000,
            'depreciation_method' => Asset::DEPRECIATION_LINEAR,
            'useful_life_years' => 7,
        ]);

        $schedule = $this->schedule($asset);

        $this->assertCount(7, $schedule);

        $total = array_sum(array_map(
            static fn (array $row): int => $row['depreciation']->minorUnits,
            $schedule,
        ));

        $this->assertSame(900_000, $total);
        $this->assertSame(900_000, $asset->depreciableBase()->minorUnits);
        $last = end($schedule);
        $this->assertNotFalse($last);
        $this->assertSame(100_000, $last['closing']->minorUnits);

        // The remainder is spread one minor unit at a time, never dropped.
        $charges = array_map(static fn (array $row): int => $row['depreciation']->minorUnits, $schedule);
        $this->assertSame([128572, 128572, 128572, 128571, 128571, 128571, 128571], $charges);
    }

    #[Test]
    public function a_linear_schedule_walks_the_book_value_down_without_gaps(): void
    {
        $user = $this->makeUser('linear-walk@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 500_000,
            'salvage_value' => 50_000,
            'depreciation_method' => Asset::DEPRECIATION_LINEAR,
            'useful_life_years' => 3,
        ]);

        $schedule = $this->schedule($asset);
        $expectedOpening = 500_000;
        $accumulated = 0;

        foreach ($schedule as $row) {
            $accumulated += $row['depreciation']->minorUnits;

            $this->assertSame($expectedOpening, $row['opening']->minorUnits);
            $this->assertSame($accumulated, $row['accumulated']->minorUnits);
            $this->assertSame($expectedOpening - $row['depreciation']->minorUnits, $row['closing']->minorUnits);
            $this->assertGreaterThanOrEqual(50_000, $row['closing']->minorUnits);

            $expectedOpening = $row['closing']->minorUnits;
        }

        $this->assertSame(50_000, $expectedOpening);
    }

    #[Test]
    public function a_declining_balance_schedule_never_drops_below_the_salvage_value(): void
    {
        $user = $this->makeUser('declining@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        // 40% a year would blow straight past a ₺3,000 salvage by year three.
        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 1_000_000,
            'salvage_value' => 300_000,
            'depreciation_method' => Asset::DEPRECIATION_DECLINING,
            'depreciation_rate' => '0.400000',
            'useful_life_years' => 5,
        ]);

        $schedule = $this->schedule($asset);
        $charges = array_map(static fn (array $row): int => $row['depreciation']->minorUnits, $schedule);

        $this->assertSame([400_000, 240_000, 60_000, 0, 0], $charges);

        foreach ($schedule as $row) {
            $this->assertGreaterThanOrEqual(
                300_000,
                $row['closing']->minorUnits,
                "Year {$row['year']} wrote the asset below its salvage value.",
            );
        }

        $this->assertSame(700_000, array_sum($charges));
        $last = end($schedule);
        $this->assertNotFalse($last);
        $this->assertSame(300_000, $last['closing']->minorUnits);
    }

    #[Test]
    public function a_declining_balance_schedule_also_sums_to_the_depreciable_base(): void
    {
        $user = $this->makeUser('declining-sum@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 1_000_000,
            'salvage_value' => 0,
            'depreciation_method' => Asset::DEPRECIATION_DECLINING,
            'depreciation_rate' => '0.300000',
            'useful_life_years' => 4,
        ]);

        $schedule = $this->schedule($asset);
        $charges = array_map(static fn (array $row): int => $row['depreciation']->minorUnits, $schedule);

        // Declining balance never reaches zero on its own, so the closing year
        // writes off whatever is left.
        $this->assertSame([300_000, 210_000, 147_000, 343_000], $charges);
        $this->assertSame(1_000_000, array_sum($charges));
        $last = end($schedule);
        $this->assertNotFalse($last);
        $this->assertSame(0, $last['closing']->minorUnits);
    }

    #[Test]
    public function an_odd_rate_still_lands_exactly_on_the_salvage_value(): void
    {
        $user = $this->makeUser('odd-rate@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 777_777,
            'salvage_value' => 12_345,
            'depreciation_method' => Asset::DEPRECIATION_DECLINING,
            'depreciation_rate' => '0.173000',
            'useful_life_years' => 9,
        ]);

        $schedule = $this->schedule($asset);
        $total = array_sum(array_map(
            static fn (array $row): int => $row['depreciation']->minorUnits,
            $schedule,
        ));

        $this->assertSame(777_777 - 12_345, $total);
        $last = end($schedule);
        $this->assertNotFalse($last);
        $this->assertSame(12_345, $last['closing']->minorUnits);

        foreach ($schedule as $row) {
            $this->assertGreaterThanOrEqual(12_345, $row['closing']->minorUnits);
        }
    }

    #[Test]
    public function an_asset_that_does_not_depreciate_has_no_schedule(): void
    {
        $user = $this->makeUser('none@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 250_000,
            'depreciation_method' => Asset::DEPRECIATION_NONE,
        ]);

        $this->assertSame([], $this->schedule($asset));
        $this->assertSame(250_000, $this->depreciation()->bookValueAfter($asset, 10)->minorUnits);
    }

    #[Test]
    public function book_value_reflects_the_whole_years_the_asset_has_been_held(): void
    {
        $user = $this->makeUser('bookvalue@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 600_000,
            'salvage_value' => 0,
            'purchase_date' => '2020-01-01',
            'depreciation_method' => Asset::DEPRECIATION_LINEAR,
            'useful_life_years' => 6,
        ]);

        $calculator = $this->depreciation();

        $this->assertSame(600_000, $calculator->bookValueOn($asset, new \DateTimeImmutable('2020-12-31'))->minorUnits);
        $this->assertSame(500_000, $calculator->bookValueOn($asset, new \DateTimeImmutable('2021-01-01'))->minorUnits);
        $this->assertSame(300_000, $calculator->bookValueOn($asset, new \DateTimeImmutable('2023-06-30'))->minorUnits);

        // Past the useful life the schedule simply runs out; it does not keep going.
        $this->assertSame(0, $calculator->bookValueOn($asset, new \DateTimeImmutable('2040-01-01'))->minorUnits);
    }

    #[Test]
    public function a_salvage_value_above_the_purchase_price_is_refused(): void
    {
        $user = $this->makeUser('salvage@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 100_000,
            'salvage_value' => 150_000,
            'depreciation_method' => Asset::DEPRECIATION_LINEAR,
            'useful_life_years' => 5,
        ]);

        try {
            $this->schedule($asset);
            $this->fail('A salvage value above the purchase price should have been refused.');
        } catch (AssetException $e) {
            $this->assertSame('salvage_above_purchase', $e->errorCode);
        }
    }

    #[Test]
    public function depreciating_without_a_useful_life_is_refused(): void
    {
        $user = $this->makeUser('nolife@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 100_000,
            'depreciation_method' => Asset::DEPRECIATION_LINEAR,
            'useful_life_years' => null,
        ]);

        try {
            $this->schedule($asset);
            $this->fail('Linear depreciation without a useful life should have been refused.');
        } catch (AssetException $e) {
            $this->assertSame('useful_life_required', $e->errorCode);
        }
    }

    #[Test]
    public function declining_balance_without_a_rate_is_refused(): void
    {
        $user = $this->makeUser('norate@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        $asset = $this->makeAsset($workspace, [
            'purchase_price' => 100_000,
            'depreciation_method' => Asset::DEPRECIATION_DECLINING,
            'useful_life_years' => 5,
        ]);

        try {
            $this->schedule($asset);
            $this->fail('Declining balance without a rate should have been refused.');
        } catch (AssetException $e) {
            $this->assertSame('depreciation_rate_required', $e->errorCode);
        }
    }

    #[Test]
    public function the_depreciation_endpoint_returns_the_schedule_as_money(): void
    {
        $user = $this->makeUser('asset-http@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        Sanctum::actingAs($user);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $id = $this->postJson('/api/v1/assets', [
            'name' => 'Delivery van',
            'kind' => 'car',
            'currency' => 'TRY',
            'purchase_price' => 500_000,
            'purchase_date' => '2024-01-01',
            'salvage_value' => 50_000,
            'depreciation_method' => 'linear',
            'useful_life_years' => 3,
        ], $headers)->assertCreated()->json('data.id');

        $this->getJson("/api/v1/assets/{$id}/depreciation", $headers)
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.depreciation.value', 150_000)
            ->assertJsonPath('data.0.depreciation.currency', 'TRY')
            ->assertJsonPath('data.0.depreciation.minor_unit', 2)
            ->assertJsonPath('data.0.depreciation.decimal', '1500.00')
            ->assertJsonPath('data.2.closing.value', 50_000)
            ->assertJsonPath('meta.depreciable_base.value', 450_000);
    }

    #[Test]
    public function a_viewer_may_read_assets_but_not_create_one(): void
    {
        $owner = $this->makeUser('asset-owner@example.test');
        $workspace = $this->makeWorkspace($owner, currency: 'TRY');
        $this->makeAsset($workspace, ['purchase_price' => 100_000]);

        $viewer = $this->makeUser('asset-viewer@example.test');
        $workspace->members()->create([
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($viewer);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $this->getJson('/api/v1/assets', $headers)->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/assets', [
            'name' => 'Sneaky flat',
            'kind' => 'house',
            'currency' => 'TRY',
            'purchase_price' => 1_000,
            'purchase_date' => '2025-01-01',
        ], $headers)->assertForbidden();
    }

    #[Test]
    public function one_workspace_never_sees_another_workspaces_assets(): void
    {
        $victim = $this->makeUser('asset-victim@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim books', 'TRY');
        $victimAsset = $this->makeAsset($victimWorkspace, [
            'name' => 'Private villa',
            'kind' => 'house',
            'purchase_price' => 900_000_000,
        ]);

        $intruder = $this->makeUser('asset-intruder@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder books', 'TRY');

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/assets', ['X-Workspace-Id' => $victimWorkspace->id])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'workspace_forbidden');

        $this->getJson("/api/v1/assets/{$victimAsset->id}", [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertNotFound();

        $this->getJson("/api/v1/assets/{$victimAsset->id}/depreciation", [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertNotFound();

        $this->getJson('/api/v1/assets', ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Fail closed: no active workspace must yield nothing, not everything.
        app(WorkspaceContext::class)->forget();
        $this->assertSame(0, Asset::query()->count());
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeAsset(Workspace $workspace, array $attributes = []): Asset
    {
        return $this->inWorkspace($workspace, fn () => Asset::query()->create($attributes + [
            'name' => 'Machine',
            'kind' => 'other',
            'currency' => 'TRY',
            'purchase_price' => 0,
            'purchase_date' => '2024-01-01',
            'salvage_value' => 0,
            'depreciation_method' => Asset::DEPRECIATION_NONE,
        ]));
    }

    /** @return list<array{year:int, opening:Money, depreciation:Money, accumulated:Money, closing:Money}> */
    private function schedule(Asset $asset): array
    {
        return $this->depreciation()->handle($asset);
    }

    private function depreciation(): CalculateDepreciation
    {
        return app(CalculateDepreciation::class);
    }
}
