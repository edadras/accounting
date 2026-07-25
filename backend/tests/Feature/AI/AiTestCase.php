<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Modules\AI\Providers\AIServiceProvider;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Tests\Feature\LedgerTestCase;

/**
 * Registers the AI provider for a test.
 *
 * It is not in bootstrap/providers.php yet — that line lands when the milestone
 * is wired into the app — so these tests bring their own migrations, routes and
 * bindings rather than depending on the wiring having happened. Same approach
 * as tests/Feature/Concerns/RegistersModuleProviders.php.
 */
abstract class AiTestCase extends LedgerTestCase
{
    use RefreshDatabase;

    protected const NOW = '2026-07-25 09:00:00';

    private static bool $aiTablesMigrated = false;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if ($app->getProvider(AIServiceProvider::class) === null) {
            $app->register(AIServiceProvider::class);
        }

        return $app;
    }

    protected function setUp(): void
    {
        if (! self::$aiTablesMigrated) {
            // The whole suite shares one in-memory database, migrated once by
            // whichever test class ran first — and that class had no reason to
            // register this provider, so ai_* is missing. Asking for one more
            // migration run, from a class that does register it, is what puts
            // the tables there without touching bootstrap/providers.php.
            RefreshDatabaseState::$migrated = false;
            self::$aiTablesMigrated = true;
        }

        parent::setUp();

        // No key: every test in this directory runs against the deterministic
        // provider and touches no network.
        config(['ai.key' => null, 'ai.enabled' => true]);

        Carbon::setTestNow(self::NOW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /** @return array{User, Workspace} */
    protected function world(string $email, string $currency = 'TRY'): array
    {
        $user = $this->makeUser($email);

        return [$user, $this->makeWorkspace($user, 'Books', $currency)];
    }

    /** @return array<string, string> */
    protected function headers(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => $workspace->id, 'Accept' => 'application/json'];
    }

    protected function wallet(Workspace $workspace): Account
    {
        return $this->inWorkspace($workspace, fn () => Account::query()->firstOrFail());
    }

    protected function categoryNamed(Workspace $workspace, string $name): Category
    {
        return $this->inWorkspace($workspace, fn () => Category::query()->where('name', $name)->firstOrFail());
    }

    protected function spend(
        Workspace $workspace,
        Account $account,
        ?Category $category,
        int $amount,
        string $at,
        ?string $description = null,
    ): Transaction {
        return $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => Transaction::TYPE_EXPENSE,
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'amount' => $amount,
            'currency' => $account->currency,
            'occurred_at' => $at,
            'description' => $description,
        ]));
    }

    protected function earn(Workspace $workspace, Account $account, int $amount, string $at): Transaction
    {
        return $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => Transaction::TYPE_INCOME,
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => $account->currency,
            'occurred_at' => $at,
        ]));
    }

    protected function context(): WorkspaceContext
    {
        return app(WorkspaceContext::class);
    }
}
