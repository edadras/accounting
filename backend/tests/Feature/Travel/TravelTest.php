<?php

declare(strict_types=1);

namespace Tests\Feature\Travel;

use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Travel\Actions\SettleTrip;
use Modules\Travel\Actions\SplitExpense as SplitExpenseAction;
use Modules\Travel\Exceptions\TravelException;
use Modules\Travel\Models\SplitExpense;
use Modules\Travel\Models\Trip;
use Modules\Travel\Models\TripMember;
use Modules\Travel\Providers\TravelServiceProvider;
use Modules\Travel\Support\Transfer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * The travel invariants: a split never loses a minor unit, and a settlement
 * leaves nobody owing anything.
 */
final class TravelTest extends LedgerTestCase
{
    use RefreshDatabase;

    /**
     * The module registers itself for the test run instead of being listed in
     * bootstrap/providers.php, so the suite is self-contained.
     */
    public function createApplication()
    {
        RegisterProviders::merge([TravelServiceProvider::class]);

        return parent::createApplication();
    }

    #[Test]
    public function an_equal_split_of_an_indivisible_amount_sums_back_exactly(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('equal@example.test'));

        // ₺100.00 three ways, ₺0.01 three ways, ₺1000.00 seven ways: none of
        // them divides, and all of them must still add back up.
        foreach ([[10000, 3], [1, 3], [100000, 7]] as [$amount, $people]) {
            [$trip, $members] = $this->makeTrip($workspace, 'TRY', $people);

            $expense = $this->split($workspace, $trip, [
                'payer_member_id' => $members[0]->id,
                'amount' => $amount,
                'mode' => 'equal',
                'participants' => $this->participants($members),
            ]);

            $shares = $expense->shares->pluck('share_amount')->all();

            $this->assertCount($people, $shares);
            $this->assertSame($amount, array_sum($shares), "Splitting {$amount} among {$people} lost a minor unit.");
            $this->assertSame($amount, $expense->shares->sum('base_share_amount'));
        }
    }

    #[Test]
    public function an_equal_split_hands_the_remainder_out_a_unit_at_a_time(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('remainder@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 3);

        $expense = $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 10000,
            'mode' => 'equal',
            'participants' => $this->participants($members),
        ]);

        // 33.34 / 33.33 / 33.33 — never three times 33.33 with a cent missing.
        $this->assertSame([3334, 3333, 3333], $this->sharesInOrder($expense, $members));
    }

    #[Test]
    public function a_weight_split_sums_back_exactly(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('weights@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 3);

        $expense = $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 10001,
            'mode' => 'weight',
            'participants' => [
                ['member_id' => $members[0]->id, 'weight' => 1],
                ['member_id' => $members[1]->id, 'weight' => 1],
                ['member_id' => $members[2]->id, 'weight' => 2],
            ],
        ]);

        $shares = $this->sharesInOrder($expense, $members);

        $this->assertSame(10001, array_sum($shares));
        $this->assertSame(5001, $shares[2], 'The double weight carries the remainder.');
    }

    #[Test]
    public function a_percent_split_sums_back_exactly(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('percent@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 3);

        $expense = $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 10000,
            'mode' => 'percent',
            'participants' => [
                ['member_id' => $members[0]->id, 'percent' => '33.34'],
                ['member_id' => $members[1]->id, 'percent' => '33.33'],
                ['member_id' => $members[2]->id, 'percent' => '33.33'],
            ],
        ]);

        $this->assertSame([3334, 3333, 3333], $this->sharesInOrder($expense, $members));
    }

    #[Test]
    public function percentages_that_do_not_reach_a_hundred_are_refused(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('badpercent@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 2);

        $this->expectException(TravelException::class);
        $this->expectExceptionMessageMatches('/not 100%/');

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 10000,
            'mode' => 'percent',
            'participants' => [
                ['member_id' => $members[0]->id, 'percent' => '50'],
                ['member_id' => $members[1]->id, 'percent' => '40'],
            ],
        ]);
    }

    #[Test]
    public function exact_mode_rejects_shares_that_do_not_add_up(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('exact@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 3);

        try {
            $this->split($workspace, $trip, [
                'payer_member_id' => $members[0]->id,
                'amount' => 10000,
                'mode' => 'exact',
                'participants' => [
                    ['member_id' => $members[0]->id, 'amount' => 3333],
                    ['member_id' => $members[1]->id, 'amount' => 3333],
                    ['member_id' => $members[2]->id, 'amount' => 3333],
                ],
            ]);

            $this->fail('A split whose shares do not add up must be refused.');
        } catch (TravelException $e) {
            $this->assertSame('exact_shares_do_not_sum', $e->errorCode);
            $this->assertStringContainsString('9999', $e->getMessage());
        }

        $this->assertSame(
            0,
            $this->inWorkspace($workspace, fn () => SplitExpense::query()->count()),
            'Nothing may be written when the split is refused.',
        );
    }

    #[Test]
    public function a_deleted_expense_drops_out_of_the_settlement(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('deleted@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 3);

        $expense = $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 9000,
            'mode' => 'equal',
            'participants' => $this->participants($members),
        ]);

        $settle = app(SettleTrip::class);
        $this->assertNotEmpty($this->inWorkspace($workspace, fn () => $settle->preview($trip)));

        $this->inWorkspace($workspace, fn () => $expense->delete());

        $this->assertSame([], $this->inWorkspace($workspace, fn () => $settle->preview($trip)));
    }

    #[Test]
    public function exact_mode_accepts_shares_that_add_up(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('exactok@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 3);

        $expense = $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 10000,
            'mode' => 'exact',
            'participants' => [
                ['member_id' => $members[0]->id, 'amount' => 5000],
                ['member_id' => $members[1]->id, 'amount' => 4000],
                ['member_id' => $members[2]->id, 'amount' => 1000],
            ],
        ]);

        $this->assertSame([5000, 4000, 1000], $this->sharesInOrder($expense, $members));
    }

    #[Test]
    public function settling_a_trip_zeroes_every_members_balance(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('settle@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 4);

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 32000,
            'mode' => 'equal',
            'participants' => $this->participants($members),
        ]);

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[1]->id,
            'amount' => 10000,
            'mode' => 'equal',
            'participants' => $this->participants([$members[1], $members[2], $members[3]]),
        ]);

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[2]->id,
            'amount' => 777,
            'mode' => 'weight',
            'participants' => [
                ['member_id' => $members[0]->id, 'weight' => 3],
                ['member_id' => $members[3]->id, 'weight' => 1],
            ],
        ]);

        $settle = app(SettleTrip::class);

        [$before, $transfers] = $this->inWorkspace($workspace, fn () => [
            $settle->balances($trip),
            $settle->preview($trip),
        ]);

        $this->assertSame(0, array_sum($before), 'Balances must net to zero before anything is paid.');
        $this->assertNotEmpty($transfers);

        $this->inWorkspace($workspace, fn () => $settle->settle($trip));

        [$after, $remaining] = $this->inWorkspace($workspace, fn () => [
            $settle->balances($trip),
            $settle->preview($trip),
        ]);

        foreach ($after as $memberId => $balance) {
            $this->assertSame(0, $balance, "Member [{$memberId}] is still out of pocket after settlement.");
        }

        $this->assertSame([], $remaining, 'A settled trip has nothing left to suggest.');
    }

    #[Test]
    public function a_six_member_trip_settles_in_at_most_five_transfers(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('six@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 6);

        $everyone = $this->participants($members);

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 60000,
            'mode' => 'equal',
            'participants' => $everyone,
        ]);

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[1]->id,
            'amount' => 9000,
            'mode' => 'equal',
            'participants' => $this->participants([$members[1], $members[2], $members[3]]),
        ]);

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[2]->id,
            'amount' => 1200,
            'mode' => 'equal',
            'participants' => $everyone,
        ]);

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[3]->id,
            'amount' => 1,
            'mode' => 'equal',
            'participants' => $this->participants([$members[4], $members[5]]),
        ]);

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[5]->id,
            'amount' => 30000,
            'mode' => 'equal',
            'participants' => $this->participants(array_slice($members, 0, 4)),
        ]);

        $settle = app(SettleTrip::class);
        $transfers = $this->inWorkspace($workspace, fn () => $settle->preview($trip));

        $this->assertLessThanOrEqual(
            count($members) - 1,
            count($transfers),
            'Six people must never need more than five payments.',
        );

        // And the few payments still settle the trip completely.
        $this->inWorkspace($workspace, fn () => $settle->settle($trip));

        $this->assertSame(
            [0, 0, 0, 0, 0, 0],
            array_values($this->inWorkspace($workspace, fn () => $settle->balances($trip))),
        );
    }

    #[Test]
    public function a_member_who_paid_nothing_and_owes_nothing_appears_in_no_transfer(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('bystander@example.test'));
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 4);
        $bystander = $members[3];

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 9999,
            'mode' => 'equal',
            'participants' => $this->participants([$members[0], $members[1], $members[2]]),
        ]);

        $settle = app(SettleTrip::class);
        [$balances, $transfers] = $this->inWorkspace($workspace, fn () => [
            $settle->balances($trip),
            $settle->preview($trip),
        ]);

        $this->assertSame(0, $balances[$bystander->id]);

        foreach ($transfers as $transfer) {
            $this->assertNotSame($bystander->id, $transfer->fromMemberId);
            $this->assertNotSame($bystander->id, $transfer->toMemberId);
        }
    }

    #[Test]
    public function expenses_in_a_local_currency_settle_in_the_trips_base_currency(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('fx@example.test'), currency: 'USD');
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 3);

        // $100.00 paid abroad, recorded at ₺32.50 to the dollar.
        $abroad = $this->split($workspace, $trip, [
            'payer_member_id' => $members[0]->id,
            'amount' => 10000,
            'currency' => 'USD',
            'fx_rate' => '32.5',
            'mode' => 'equal',
            'participants' => $this->participants($members),
        ]);

        $this->assertSame('USD', $abroad->currency);
        $this->assertSame(325000, $abroad->base_amount);
        $this->assertSame(10000, $abroad->shares->sum('share_amount'));
        $this->assertSame(325000, $abroad->shares->sum('base_share_amount'), 'Base shares must sum to the base amount.');

        $this->split($workspace, $trip, [
            'payer_member_id' => $members[1]->id,
            'amount' => 60000,
            'currency' => 'TRY',
            'mode' => 'equal',
            'participants' => $this->participants($members),
        ]);

        $settle = app(SettleTrip::class);
        $transfers = $this->inWorkspace($workspace, fn () => $settle->preview($trip));

        foreach ($transfers as $transfer) {
            $this->assertSame('TRY', $transfer->amount->currency->code, 'Settlement is in the trip base currency.');
        }

        $paidToPayer = array_sum(array_map(
            fn (Transfer $transfer) => $transfer->toMemberId === $members[0]->id ? $transfer->amount->minorUnits : 0,
            $transfers,
        ));

        // ₺3250 paid, ₺1083.34 owed of it, minus the ₺200 share of the second
        // expense: ₺1966.66 comes back.
        $this->assertSame(196666, $paidToPayer);

        $this->inWorkspace($workspace, fn () => $settle->settle($trip));

        $this->assertSame(
            [0, 0, 0],
            array_values($this->inWorkspace($workspace, fn () => $settle->balances($trip))),
        );
    }

    #[Test]
    public function random_trips_always_settle_completely_and_in_at_most_n_minus_one_payments(): void
    {
        // The two properties that matter, checked broadly rather than at a few
        // hand-picked numbers.
        mt_srand(20260725);

        $workspace = $this->makeWorkspace($this->makeUser('property@example.test'));
        $settle = app(SettleTrip::class);

        for ($round = 0; $round < 25; $round++) {
            $people = mt_rand(2, 8);
            [$trip, $members] = $this->makeTrip($workspace, 'TRY', $people);

            for ($i = 0; $i < mt_rand(1, 5); $i++) {
                $participants = array_slice($members, 0, mt_rand(1, $people));
                shuffle($participants);

                $weighted = mt_rand(0, 1) === 1;

                $this->split($workspace, $trip, [
                    'payer_member_id' => $members[mt_rand(0, $people - 1)]->id,
                    'amount' => mt_rand(1, 5_000_000),
                    'mode' => $weighted ? 'weight' : 'equal',
                    'participants' => array_map(
                        fn (TripMember $member) => $weighted
                            ? ['member_id' => $member->id, 'weight' => mt_rand(1, 5)]
                            : ['member_id' => $member->id],
                        $participants,
                    ),
                ]);
            }

            $transfers = $this->inWorkspace($workspace, fn () => $settle->preview($trip));

            $this->assertLessThanOrEqual(
                $people - 1,
                count($transfers),
                "Round {$round}: {$people} members needed ".count($transfers).' payments.',
            );

            $this->inWorkspace($workspace, fn () => $settle->settle($trip));

            foreach ($this->inWorkspace($workspace, fn () => $settle->balances($trip)) as $memberId => $balance) {
                $this->assertSame(0, $balance, "Round {$round}: member [{$memberId}] is still owed {$balance}.");
            }
        }
    }

    #[Test]
    public function the_api_speaks_money_as_value_currency_minor_unit_and_decimal(): void
    {
        $owner = $this->makeUser('api@example.test');
        $workspace = $this->makeWorkspace($owner, currency: 'TRY');

        Sanctum::actingAs($owner);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $trip = $this->postJson('/api/v1/trips', [
            'name' => 'Cappadocia',
            'destination' => 'Nevşehir',
            'base_currency' => 'TRY',
            'members' => [
                ['display_name' => 'Ali', 'weight' => 1],
                ['display_name' => 'Mina', 'weight' => 1],
                ['display_name' => 'Reza', 'weight' => 1],
            ],
        ], $headers)->assertCreated()->json('data');

        $memberIds = array_column($trip['members'], 'id');

        $expense = $this->postJson("/api/v1/trips/{$trip['id']}/expenses", [
            'payer_member_id' => $memberIds[0],
            'amount' => 10000,
            'currency' => 'TRY',
            'description' => 'Balloon ride',
            'mode' => 'equal',
            'participants' => array_map(fn (string $id) => ['member_id' => $id], $memberIds),
        ], $headers)->assertCreated()->json('data');

        $this->assertSame(
            ['value' => 10000, 'currency' => 'TRY', 'minor_unit' => 2, 'decimal' => '100.00'],
            $expense['amount'],
        );
        $this->assertSame(10000, array_sum(array_column(array_column($expense['shares'], 'amount'), 'value')));

        $this->getJson("/api/v1/trips/{$trip['id']}/expenses", $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $preview = $this->getJson("/api/v1/trips/{$trip['id']}/settlement/preview", $headers)
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $preview['transfers']);
        $this->assertSame('TRY', $preview['transfers'][0]['amount']['currency']);

        $this->postJson("/api/v1/trips/{$trip['id']}/settlement", [], $headers)
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/trips/{$trip['id']}/settlement/preview", $headers)
            ->assertOk()
            ->assertJsonPath('data.transfers', []);
    }

    #[Test]
    public function a_viewer_may_read_a_trip_but_not_spend_on_it(): void
    {
        $owner = $this->makeUser('tripowner@example.test');
        $workspace = $this->makeWorkspace($owner);
        [$trip, $members] = $this->makeTrip($workspace, 'TRY', 2);

        $viewer = $this->makeUser('tripviewer@example.test');
        $workspace->members()->create([
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($viewer);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $this->getJson("/api/v1/trips/{$trip->id}", $headers)->assertOk();

        $this->postJson("/api/v1/trips/{$trip->id}/expenses", [
            'payer_member_id' => $members[0]->id,
            'amount' => 1000,
            'currency' => 'TRY',
        ], $headers)->assertForbidden();

        $this->postJson("/api/v1/trips/{$trip->id}/settlement", [], $headers)->assertForbidden();
    }

    #[Test]
    public function one_workspace_can_neither_see_nor_touch_anothers_trip(): void
    {
        $victim = $this->makeUser('tripvictim@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim trips');
        [$victimTrip, $victimMembers] = $this->makeTrip($victimWorkspace, 'TRY', 2);

        $this->split($victimWorkspace, $victimTrip, [
            'payer_member_id' => $victimMembers[0]->id,
            'amount' => 5000,
            'mode' => 'equal',
            'participants' => $this->participants($victimMembers),
        ]);

        $intruder = $this->makeUser('tripintruder@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder trips');

        Sanctum::actingAs($intruder);

        // Their own header plus somebody else's id: only the global scope can
        // stop this one.
        $this->getJson("/api/v1/trips/{$victimTrip->id}", ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'trip_not_found');

        $this->getJson("/api/v1/trips/{$victimTrip->id}/expenses", ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertNotFound();

        $this->postJson("/api/v1/trips/{$victimTrip->id}/expenses", [
            'payer_member_id' => $victimMembers[0]->id,
            'amount' => 1000,
            'currency' => 'TRY',
        ], ['X-Workspace-Id' => $intruderWorkspace->id])->assertNotFound();

        // The victim's own workspace header is refused outright.
        $this->getJson("/api/v1/trips/{$victimTrip->id}", ['X-Workspace-Id' => $victimWorkspace->id])
            ->assertForbidden();

        $this->getJson('/api/v1/trips', ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertOk()
            ->assertJsonPath('data', []);

        // Fail closed: with no active workspace the tables read as empty.
        app(WorkspaceContext::class)->forget();

        $this->assertSame(0, Trip::query()->count());
        $this->assertSame(0, SplitExpense::query()->count());
    }

    /**
     * @param  list<TripMember>  $members
     * @return list<array{member_id: string}>
     */
    private function participants(array $members): array
    {
        return array_map(fn (TripMember $member) => ['member_id' => $member->id], $members);
    }

    /**
     * @param  list<TripMember>  $members
     * @return list<int>
     */
    private function sharesInOrder(SplitExpense $expense, array $members): array
    {
        $byMember = $expense->shares->keyBy('member_id');

        return array_values(array_filter(array_map(
            fn (TripMember $member) => $byMember->get($member->id)?->share_amount,
            $members,
        ), fn (?int $share) => $share !== null));
    }

    /** @return array{0: Trip, 1: list<TripMember>} */
    private function makeTrip(Workspace $workspace, string $currency, int $people): array
    {
        return $this->inWorkspace($workspace, function () use ($currency, $people): array {
            $trip = Trip::query()->create([
                'name' => 'Trip of '.$people,
                'destination' => 'Somewhere',
                'starts_at' => now(),
                'base_currency' => $currency,
            ]);

            $members = [];

            for ($i = 1; $i <= $people; $i++) {
                $members[] = TripMember::query()->create([
                    'trip_id' => $trip->id,
                    'display_name' => 'Member '.$i,
                    'weight' => 1,
                ]);
            }

            return [$trip, $members];
        });
    }

    /**
     * @param  array{
     *   id?: string,
     *   payer_member_id: string,
     *   amount: int,
     *   currency?: string|null,
     *   fx_rate?: float|string|null,
     *   category_id?: string|null,
     *   occurred_at?: \DateTimeInterface|string|null,
     *   description?: string|null,
     *   latitude?: float|null,
     *   longitude?: float|null,
     *   mode?: string,
     *   participants?: list<array{member_id: string, percent?: string|float|int, weight?: int, amount?: int}>,
     * }  $data
     */
    private function split(Workspace $workspace, Trip $trip, array $data): SplitExpense
    {
        return $this->inWorkspace(
            $workspace,
            fn () => app(SplitExpenseAction::class)->handle($trip, $data),
        );
    }
}
