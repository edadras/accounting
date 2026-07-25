<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Exceptions\SyncException;
use Modules\Sync\Models\Device;
use Modules\Sync\Models\SyncChange;
use Modules\Sync\Providers\SyncServiceProvider;
use Modules\Sync\Support\SyncRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * The promise of docs/09-sync-offline.md: the user records expenses on the
 * underground, on a plane and in a village with no signal, and when the phone
 * finds a network again nothing is lost, nothing is duplicated, and no amount
 * is ever silently overwritten.
 */
final class SyncTest extends LedgerTestCase
{
    use RefreshDatabase {
        refreshTestDatabase as private laravelRefreshTestDatabase;
    }

    /**
     * Sync is not in bootstrap/providers.php yet — that line lands when the
     * milestone is wired into the app — so the test brings its own migrations
     * and routes rather than depending on the wiring having happened.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app->getProvider(SyncServiceProvider::class) === null) {
            $app->register(SyncServiceProvider::class);
        }

        return $app;
    }

    /**
     * A test class that ran earlier in the suite migrated the shared in-memory
     * database before this provider existed, so the two sync tables are missing
     * from it. They are added on their own rather than with a `migrate:fresh`,
     * which would drop everything the other classes have already built.
     */
    protected function refreshTestDatabase(): void
    {
        if (RefreshDatabaseState::$migrated && ! Schema::hasTable('sync_changes')) {
            $this->artisan('migrate', [
                '--path' => 'modules/Sync/Database/Migrations',
                '--force' => true,
            ]);
        }

        $this->laravelRefreshTestDatabase();
    }

    #[Test]
    public function fifty_expenses_recorded_in_flight_all_land_and_none_is_duplicated(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $batch = $this->offlineBatch($account, 50);

        $response = $this->push($user, $workspace, $batch, $device);

        $response->assertOk();

        $results = $response->json('results');

        $this->assertCount(50, $results);
        $this->assertSame(['applied'], array_values(array_unique(array_column($results, 'status'))));
        $this->assertSame([1], array_values(array_unique(array_column($results, 'server_version'))));

        $this->assertSame(50, $this->countTransactions($workspace));
        $this->assertCount(50, array_unique(array_column($batch, 'id')));

        foreach ($batch as $change) {
            $this->assertNotNull($this->transaction($workspace, $change['id']));
        }
    }

    #[Test]
    public function replaying_the_identical_batch_changes_nothing_and_answers_the_same(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $batch = $this->offlineBatch($account, 50);

        $first = $this->push($user, $workspace, $batch, $device)->json('results');

        // The socket died before the response arrived, so the device sends the
        // whole outbox again — the normal case, not an edge case.
        $second = $this->push($user, $workspace, $batch, $device)->json('results');

        $this->assertSame($first, $second);
        $this->assertSame(50, $this->countTransactions($workspace));

        // And a third time, for good measure.
        $this->assertSame($first, $this->push($user, $workspace, $batch, $device)->json('results'));
        $this->assertSame(50, $this->countTransactions($workspace));
    }

    #[Test]
    public function replaying_a_batch_that_conflicted_returns_the_same_conflict(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $id = $this->create($user, $workspace, $account, $device, 5_000);
        $this->bumpAmount($user, $workspace, $device, $id, 7_000);

        $stale = [$this->change($id, 'update', 1, ['amount' => 9_000])];

        $first = $this->push($user, $workspace, $stale, $device)->json('results');
        $second = $this->push($user, $workspace, $stale, $device)->json('results');

        $this->assertSame('conflict', $first[0]['status']);
        $this->assertSame($first, $second);
        $this->assertSame(7_000, (int) $this->storedTransaction($workspace, $id)->amount);
    }

    #[Test]
    public function a_stale_amount_conflicts_and_the_stored_amount_is_untouched(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $id = $this->create($user, $workspace, $account, $device, 5_000);

        // Another device gets there first.
        $this->bumpAmount($user, $workspace, $device, $id, 7_000);

        // This one was still working from version 1.
        $response = $this->push($user, $workspace, [
            $this->change($id, 'update', 1, ['amount' => 9_000]),
        ], $device);

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'conflict');
        $response->assertJsonPath('results.0.reason', 'financial_conflict');
        $response->assertJsonPath('results.0.server_version', 2);

        // The conflict is useless without the other side of the argument.
        $response->assertJsonPath('results.0.server_payload.amount', 7_000);
        $response->assertJsonPath('results.0.server_payload.version', 2);

        $this->assertSame(7_000, (int) $this->storedTransaction($workspace, $id)->amount);
    }

    #[Test]
    public function a_stale_description_merges_instead_of_conflicting(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $id = $this->create($user, $workspace, $account, $device, 5_000);
        $this->bumpAmount($user, $workspace, $device, $id, 7_000);

        // The offline device only ever touched the description; the financial
        // fields it echoes back are simply the ones it last saw.
        $response = $this->push($user, $workspace, [
            $this->change($id, 'update', 1, [
                'amount' => 7_000,
                'currency' => 'TRY',
                'account_id' => $account->id,
                'description' => 'coffee on the metro',
            ]),
        ], $device);

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'merged');
        $response->assertJsonPath('results.0.server_version', 3);

        $transaction = $this->storedTransaction($workspace, $id);

        $this->assertSame('coffee on the metro', $transaction->description);
        $this->assertSame(7_000, (int) $transaction->amount);
    }

    #[Test]
    public function a_delete_never_silently_beats_an_edit(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $id = $this->create($user, $workspace, $account, $device, 5_000);
        $this->bumpAmount($user, $workspace, $device, $id, 7_000);

        // The other device deleted the row while offline and only knows v1.
        $response = $this->push($user, $workspace, [
            $this->change($id, 'delete', 1, []),
        ], $device);

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'conflict');
        $response->assertJsonPath('results.0.reason', 'delete_vs_edit');

        $transaction = $this->transaction($workspace, $id);

        $this->assertNotNull($transaction);
        $this->assertFalse($transaction->trashed());
        $this->assertSame(7_000, (int) $transaction->amount);
    }

    #[Test]
    public function an_edit_arriving_after_a_delete_conflicts_rather_than_resurrecting_the_row(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $id = $this->create($user, $workspace, $account, $device, 5_000);

        $this->push($user, $workspace, [$this->change($id, 'delete', 1, [])], $device)
            ->assertJsonPath('results.0.status', 'applied');

        $response = $this->push($user, $workspace, [
            $this->change($id, 'update', 1, ['description' => 'typed on the plane']),
        ], $device);

        $response->assertJsonPath('results.0.status', 'conflict');
        $response->assertJsonPath('results.0.reason', 'deleted_on_server');

        $transaction = $this->storedTransaction($workspace, $id);

        $this->assertTrue($transaction->trashed());
        $this->assertNotSame('typed on the plane', $transaction->description);
    }

    #[Test]
    public function pull_returns_everything_written_since_a_moment_and_carries_the_server_clock(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $since = $this->moveOn();

        $ids = array_column($this->offlineBatch($account, 12), 'id');
        $this->push($user, $workspace, $this->offlineBatchFor($account, $ids), $device)->assertOk();

        $response = $this->pull($user, $workspace, ['since' => $since], $device);

        $response->assertOk();

        $pulled = $response->json('data');
        $transactionIds = $this->idsOf($pulled, 'transaction');

        $this->assertEqualsCanonicalizing($ids, $transactionIds);
        $this->assertNull($response->json('next_cursor'));

        $serverTime = $response->json('server_time');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $serverTime);
        $this->assertLessThanOrEqual(
            5,
            abs(CarbonImmutable::parse((string) $serverTime)->diffInSeconds(CarbonImmutable::now())),
        );

        // Every row carries its version and a payload the client can store.
        $first = $pulled[0];
        $this->assertArrayHasKey('version', $first);
        $this->assertArrayHasKey('payload', $first);
        $this->assertSame($first['id'], $first['payload']['id']);
    }

    #[Test]
    public function walking_the_cursor_yields_every_row_exactly_once(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $since = $this->moveOn();

        // All written inside the same second, so `updated_at` alone cannot order
        // them and the cursor has to lean on the entity and the id as well.
        $ids = array_column($this->offlineBatch($account, 25), 'id');
        $this->push($user, $workspace, $this->offlineBatchFor($account, $ids), $device)->assertOk();

        $whole = $this->pull($user, $workspace, ['since' => $since, 'limit' => 200], $device);
        $whole->assertOk();
        $this->assertNull($whole->json('next_cursor'));

        $expected = $this->keysOf($whole->json('data'));

        $collected = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->pull($user, $workspace, array_filter([
                'since' => $since,
                'limit' => 7,
                'cursor' => $cursor,
            ]), $device);

            $response->assertOk();

            $page = $response->json('data');
            $this->assertLessThanOrEqual(7, count($page));

            $collected = [...$collected, ...$this->keysOf($page)];
            $cursor = $response->json('next_cursor');
            $pages++;

            $this->assertLessThan(50, $pages, 'The cursor never reached the end.');
        } while ($cursor !== null);

        $this->assertGreaterThan(1, $pages);

        // No repeats …
        $this->assertSame(count($collected), count(array_unique($collected)));

        // … and no gaps.
        $this->assertEqualsCanonicalizing($expected, $collected);
        $this->assertEqualsCanonicalizing(
            $ids,
            $this->idsOf(array_map($this->split(...), $collected), 'transaction'),
        );
    }

    #[Test]
    public function a_device_clock_set_far_in_the_past_or_the_future_does_not_break_pull(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        $ids = array_column($this->offlineBatch($account, 5), 'id');
        $this->push($user, $workspace, $this->offlineBatchFor($account, $ids), $device)->assertOk();

        // A phone whose clock never left 1970.
        $past = $this->pull($user, $workspace, ['since' => '1970-01-01T00:00:00Z'], $device);
        $past->assertOk();
        $this->assertEqualsCanonicalizing($ids, $this->idsOf($past->json('data'), 'transaction'));

        // A phone that thinks it is the year 3000: nothing is newer than that,
        // but the answer is still a valid page anchored to the server's clock.
        $future = $this->pull($user, $workspace, ['since' => '3000-01-01T00:00:00Z'], $device);
        $future->assertOk();
        $this->assertSame([], $future->json('data'));
        $this->assertNull($future->json('next_cursor'));
        $this->assertNotNull($future->json('server_time'));
        $this->assertStringStartsWith(
            CarbonImmutable::now()->utc()->format('Y-m-d'),
            (string) $future->json('server_time'),
        );

        // Something that is not a timestamp at all is a refusal, not a crash.
        $this->pull($user, $workspace, ['since' => 'yesterday-ish'], $device)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_timestamp');

        $this->pull($user, $workspace, ['cursor' => 'not-a-cursor-we-issued'], $device)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_cursor');
    }

    #[Test]
    public function an_entity_the_registry_does_not_know_is_refused(): void
    {
        [$user, $workspace, $account, $device] = $this->world();

        foreach (['user', 'users', 'workspace', User::class, 'App\Models\User'] as $entity) {
            $response = $this->push($user, $workspace, [[
                'entity' => $entity,
                'id' => (string) Str::ulid(),
                'op' => 'create',
                'base_version' => 0,
                'payload' => ['name' => 'intruder', 'email' => 'intruder@example.test'],
            ]], $device);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('changes.0.entity');
        }

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, SyncChange::query()->withoutWorkspaceScope()->count());

        // And below the HTTP layer the registry refuses just as flatly, so the
        // string can never reach `new $class`.
        $registry = app(SyncRegistry::class);

        $this->assertSame(['transaction', 'account', 'category', 'budget'], $registry->keys());
        $this->assertFalse($registry->has(User::class));

        $this->expectException(SyncException::class);
        $registry->resolve(User::class);
    }

    #[Test]
    public function a_device_cannot_push_into_or_pull_from_another_workspace(): void
    {
        [$owner, $ownerWorkspace, $ownerAccount, $ownerDevice] = $this->world('owner@example.test', 'Owner books');
        [$intruder, $intruderWorkspace, , $intruderDevice] = $this->world('intruder@example.test', 'Intruder books');

        $secretId = $this->create($owner, $ownerWorkspace, $ownerAccount, $ownerDevice, 5_000);

        // Naming the other workspace in the header gets nowhere.
        $this->push($intruder, $ownerWorkspace, [
            $this->change($secretId, 'update', 1, ['amount' => 1]),
        ], $intruderDevice)->assertForbidden();

        $this->pull($intruder, $ownerWorkspace, [], $intruderDevice)->assertForbidden();

        // Nor does pushing the other workspace's row id into one's own books:
        // the workspace scope simply does not see it.
        $response = $this->push($intruder, $intruderWorkspace, [
            $this->change($secretId, 'update', 1, ['amount' => 1]),
        ], $intruderDevice);

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'conflict');
        $response->assertJsonPath('results.0.reason', 'entity_missing');
        $response->assertJsonPath('results.0.server_payload', null);

        $this->assertSame(0, $this->countTransactions($intruderWorkspace));
        $this->assertSame(5_000, (int) $this->storedTransaction($ownerWorkspace, $secretId)->amount);

        // The intruder's pull of their own workspace stays empty of it too.
        $mine = $this->pull($intruder, $intruderWorkspace, ['since' => '1970-01-01T00:00:00Z'], $intruderDevice);
        $mine->assertOk();
        $this->assertNotContains($secretId, array_column($mine->json('data'), 'id'));

        // And someone else's device id is not a key to anything.
        $this->push($intruder, $intruderWorkspace, [
            $this->change((string) Str::ulid(), 'update', 0, ['amount' => 1]),
        ], $ownerDevice)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'device_not_found');
    }

    #[Test]
    public function a_device_registers_lists_and_is_revoked(): void
    {
        [$user, $workspace, $account] = $this->world();

        Sanctum::actingAs($user);

        $id = (string) Str::ulid();

        $this->postJson('/api/v1/devices', [
            'id' => $id,
            'platform' => 'android',
            'name' => 'Pixel 9',
            'push_token' => 'secret-token',
        ], $this->headers($workspace))
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.has_push_token', true)
            ->assertJsonMissingPath('data.push_token');

        // Re-registering on every launch must be harmless.
        $this->postJson('/api/v1/devices', ['id' => $id, 'platform' => 'android'], $this->headers($workspace))
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->getJson('/api/v1/devices', $this->headers($workspace))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->deleteJson("/api/v1/devices/{$id}", [], $this->headers($workspace))
            ->assertOk()
            ->assertJsonPath('data.has_push_token', false);

        $this->assertNotNull(Device::query()->find($id)?->revoked_at);

        // A revoked device may not sync again.
        $this->postJson('/api/v1/sync/push', [
            'device_id' => $id,
            'changes' => [$this->change((string) Str::ulid(), 'create', 0, [
                'type' => 'expense', 'account_id' => $account->id, 'amount' => 100, 'currency' => 'TRY',
            ])],
        ], $this->headers($workspace))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'device_revoked');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: User, 1: Workspace, 2: Account, 3: Device} */
    private function world(string $email = 'ali@example.test', string $name = 'Personal'): array
    {
        $user = $this->makeUser($email);
        $workspace = $this->makeWorkspace($user, $name);
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        $device = Device::query()->create([
            'user_id' => $user->id,
            'platform' => 'android',
            'name' => 'Pixel',
        ]);

        return [$user, $workspace, $account, $device];
    }

    /** @return list<array<string, mixed>> */
    private function offlineBatch(Account $account, int $count): array
    {
        return $this->offlineBatchFor(
            $account,
            array_map(static fn (): string => (string) Str::ulid(), range(1, $count)),
        );
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function offlineBatchFor(Account $account, array $ids): array
    {
        $changes = [];

        foreach ($ids as $index => $id) {
            $changes[] = $this->change($id, 'create', 0, [
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => 1_000 + $index,
                'currency' => 'TRY',
                'occurred_at' => sprintf('2026-07-%02dT10:00:00Z', ($index % 28) + 1),
                'description' => "offline expense {$index}",
            ]);
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{entity: string, id: string, op: string, base_version: int, payload: array<string, mixed>}
     */
    private function change(string $id, string $op, int $baseVersion, array $payload): array
    {
        return [
            'entity' => 'transaction',
            'id' => $id,
            'op' => $op,
            'base_version' => $baseVersion,
            'payload' => $payload,
        ];
    }

    private function create(
        User $user,
        Workspace $workspace,
        Account $account,
        Device $device,
        int $amount,
    ): string {
        $id = (string) Str::ulid();

        $this->push($user, $workspace, [$this->change($id, 'create', 0, [
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => 'TRY',
            'occurred_at' => '2026-07-20T10:00:00Z',
            'description' => 'as first recorded',
        ])], $device)->assertOk()->assertJsonPath('results.0.status', 'applied');

        return $id;
    }

    private function bumpAmount(User $user, Workspace $workspace, Device $device, string $id, int $amount): void
    {
        $this->push($user, $workspace, [$this->change($id, 'update', 1, ['amount' => $amount])], $device)
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.server_version', 2);
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function push(User $user, Workspace $workspace, array $changes, ?Device $device = null): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/sync/push', array_filter([
            'device_id' => $device?->id,
            'changes' => $changes,
        ], static fn (mixed $value): bool => $value !== null), $this->headers($workspace));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<\Illuminate\Http\Response>
     */
    private function pull(User $user, Workspace $workspace, array $query, ?Device $device = null): TestResponse
    {
        Sanctum::actingAs($user);

        $headers = $this->headers($workspace);

        if ($device !== null) {
            $headers['X-Device-Id'] = $device->id;
        }

        return $this->getJson('/api/v1/sync/pull?'.http_build_query($query), $headers);
    }

    /** Whatever the server holds under $id, deleted rows included — or nothing. */
    private function transaction(Workspace $workspace, string $id): ?Transaction
    {
        return $this->inWorkspace(
            $workspace,
            fn () => Transaction::query()->withTrashed()->find($id),
        );
    }

    /**
     * The row the test has just pushed, which the server is expected to hold.
     *
     * Its absence is the test failing rather than a null worth threading
     * through the assertions that follow.
     */
    private function storedTransaction(Workspace $workspace, string $id): Transaction
    {
        return $this->inWorkspace(
            $workspace,
            fn () => Transaction::query()->withTrashed()->findOrFail($id),
        );
    }

    private function countTransactions(Workspace $workspace): int
    {
        return $this->inWorkspace($workspace, fn () => Transaction::query()->count());
    }

    /** The moment a client would record as "I am up to date", a beat after setup. */
    private function moveOn(): string
    {
        $this->travel(3)->seconds();

        $since = CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z');

        $this->travel(2)->seconds();

        return $since;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function idsOf(array $rows, string $entity): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['id'],
            array_filter($rows, static fn (array $row): bool => $row['entity'] === $entity),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function keysOf(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['entity'].':'.$row['id'], $rows);
    }

    /** @return array<string, string> */
    private function split(string $key): array
    {
        [$entity, $id] = explode(':', $key, 2);

        return ['entity' => $entity, 'id' => $id];
    }

    /** @return array<string, string> */
    private function headers(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => $workspace->id, 'Accept' => 'application/json'];
    }
}
