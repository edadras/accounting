<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Contracts\WritableSearchEngine;
use Modules\Search\Engines\DatabaseSearchEngine;
use Modules\Search\Engines\MeilisearchEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersModuleProviders;
use Tests\Feature\LedgerTestCase;

/**
 * docs/01 names Meilisearch for production, so the engine exists and is bound
 * by `search.driver`.
 *
 * The tests that need a running server skip, loudly, when there is not one.
 * That is deliberate: this environment has no Meilisearch, and a test suite
 * that reported green for an integration nobody ran would be worse than no
 * test at all. Start the container from docker-compose.yml and set
 * MEILISEARCH_HOST to see them actually execute.
 *
 * The tests above the skip need no server and always run — the binding, the
 * default, and the fact that swapping the engine changes nothing a caller can
 * see.
 */
final class MeilisearchEngineTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersModuleProviders;

    private const MEAT = 'گوشت';

    #[Test]
    public function the_database_engine_is_the_default(): void
    {
        $this->assertSame('database', config('search.driver'));
        $this->assertInstanceOf(DatabaseSearchEngine::class, app(SearchEngine::class));

        // And it is not asked to keep a second copy of anything, because for
        // it the table is the index.
        $this->assertNotInstanceOf(WritableSearchEngine::class, app(SearchEngine::class));
    }

    #[Test]
    public function configuring_the_driver_swaps_the_engine_behind_the_contract(): void
    {
        config(['search.driver' => 'meilisearch']);
        $this->refreshApplication();
        config(['search.driver' => 'meilisearch']);

        $engine = app(SearchEngine::class);

        $this->assertInstanceOf(MeilisearchEngine::class, $engine);
        $this->assertInstanceOf(WritableSearchEngine::class, $engine);
    }

    #[Test]
    public function it_answers_nothing_rather_than_reaching_out_when_no_workspace_is_active(): void
    {
        // No server is contacted on this path at all: with no workspace there
        // is no filter that could be safely applied, so the engine refuses
        // before it opens a socket.
        $this->assertSame(
            array_fill_keys(SearchEngine::TYPES, []),
            $this->engine()->search(self::MEAT),
        );
    }

    #[Test]
    public function it_indexes_and_finds_a_transaction(): void
    {
        $engine = $this->requireServer();

        [$user, $workspace] = $this->world();

        $engine->configure();

        $transaction = $this->transaction($workspace, 'خريد گوشت از قصابي');

        Sanctum::actingAs($user);

        $found = $this->inWorkspace($workspace, fn () => $engine->search(self::MEAT));

        $this->assertSame(
            [$transaction->id],
            array_column($found[SearchEngine::TYPE_TRANSACTIONS], 'id'),
        );
    }

    #[Test]
    public function it_never_returns_another_workspaces_rows(): void
    {
        $engine = $this->requireServer();

        [, $mine] = $this->world('owner@example.test', 'Owner books');
        [, $theirs] = $this->world('intruder@example.test', 'Intruder books');

        $engine->configure();

        $secret = $this->transaction($mine, 'گوشت مخصوص');

        $found = $this->inWorkspace($theirs, fn () => $engine->search(self::MEAT));

        $this->assertSame([], $found[SearchEngine::TYPE_TRANSACTIONS]);

        $found = $this->inWorkspace($mine, fn () => $engine->search(self::MEAT));

        $this->assertSame([$secret->id], array_column($found[SearchEngine::TYPE_TRANSACTIONS], 'id'));
    }

    #[Test]
    public function a_deleted_record_leaves_the_index(): void
    {
        $engine = $this->requireServer();

        [, $workspace] = $this->world();

        $engine->configure();

        $transaction = $this->transaction($workspace, 'گوشت گوسفندی');

        $this->inWorkspace($workspace, fn () => $transaction->forceDelete());

        $found = $this->inWorkspace($workspace, fn () => $engine->search(self::MEAT));

        $this->assertSame([], $found[SearchEngine::TYPE_TRANSACTIONS]);
    }

    private function engine(): MeilisearchEngine
    {
        return app(MeilisearchEngine::class);
    }

    /**
     * The engine, or a skip that says exactly what is missing and how to get
     * it — never a pass for something that did not run.
     */
    private function requireServer(): MeilisearchEngine
    {
        config(['search.driver' => 'meilisearch']);

        $engine = $this->engine();

        if (! $engine->isReachable()) {
            $host = (string) config('search.meilisearch.host');

            $this->markTestSkipped(
                "Meilisearch is not running at {$host}, so this integration was not exercised. "
                .'Start it with `docker compose up meilisearch` (see docker-compose.yml) and set '
                .'MEILISEARCH_HOST / MEILISEARCH_KEY to run these tests.'
            );
        }

        return $engine;
    }

    /** @return array{0: User, 1: Workspace} */
    private function world(string $email = 'ali@example.test', string $name = 'Personal'): array
    {
        $user = $this->makeUser($email);
        $workspace = $this->makeWorkspace($user, $name);

        $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        return [$user, $workspace];
    }

    private function transaction(Workspace $workspace, string $description): Transaction
    {
        return $this->inWorkspace($workspace, function () use ($description): Transaction {
            $account = Account::query()->where('name', 'Wallet')->firstOrFail();

            return app(RecordTransaction::class)->handle([
                'type' => Transaction::TYPE_EXPENSE,
                'account_id' => $account->id,
                'amount' => 1000,
                'currency' => 'TRY',
                'description' => $description,
            ]);
        });
    }
}
