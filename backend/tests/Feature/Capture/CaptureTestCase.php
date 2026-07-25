<?php

declare(strict_types=1);

namespace Tests\Feature\Capture;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Modules\AI\Providers\AIServiceProvider;
use Modules\Capture\Providers\CaptureServiceProvider;
use Modules\Core\Models\Workspace;
use Modules\Documents\Providers\DocumentsServiceProvider;
use Modules\Ledger\Models\Account;
use Tests\Feature\LedgerTestCase;

/**
 * Registers the Capture provider for a test.
 *
 * It is not in bootstrap/providers.php yet — that line lands when the milestone
 * is wired into the app — so these tests bring their own migrations, routes and
 * config rather than depending on the wiring having happened. Same approach as
 * tests/Feature/AI/AiTestCase.php.
 */
abstract class CaptureTestCase extends LedgerTestCase
{
    use RefreshDatabase;

    protected const NOW = '2026-07-25 09:00:00';

    protected const WEBHOOK_SECRET = 'test-inbound-mail-secret';

    /** @var list<class-string> */
    private const PROVIDERS = [
        AIServiceProvider::class,
        DocumentsServiceProvider::class,
        CaptureServiceProvider::class,
    ];

    private static bool $captureTablesMigrated = false;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        foreach (self::PROVIDERS as $provider) {
            if ($app->getProvider($provider) === null) {
                $app->register($provider);
            }
        }

        return $app;
    }

    protected function setUp(): void
    {
        if (! self::$captureTablesMigrated) {
            // The whole suite shares one in-memory database, migrated once by
            // whichever test class ran first — and that class had no reason to
            // register this provider, so capture_* is missing. Asking for one
            // more migration run, from a class that does register it, is what
            // puts the tables there without touching bootstrap/providers.php.
            RefreshDatabaseState::$migrated = false;
            self::$captureTablesMigrated = true;
        }

        parent::setUp();

        // No key: every test in this directory runs against the deterministic
        // provider and touches no network.
        config([
            'ai.key' => null,
            'ai.enabled' => true,
            'capture.webhook.secret' => self::WEBHOOK_SECRET,
        ]);

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

    /** @return array<string, string> */
    protected function webhookHeaders(string $secret = self::WEBHOOK_SECRET): array
    {
        return ['X-Capture-Secret' => $secret, 'Accept' => 'application/json'];
    }

    protected function wallet(Workspace $workspace): Account
    {
        return $this->inWorkspace($workspace, fn () => Account::query()->firstOrFail());
    }

    /** A card whose last four digits a bank SMS can be matched against. */
    protected function card(Workspace $workspace, string $last4, string $currency = 'IRR'): Account
    {
        return $this->inWorkspace($workspace, fn () => Account::query()->create([
            'name' => 'Card '.$last4,
            'type' => 'card',
            'currency' => $currency,
            'opening_balance' => 0,
            'current_balance' => 0,
            'card_last4' => $last4,
        ]));
    }
}
