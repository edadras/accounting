<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Actions\CancelSubscription;
use Modules\Billing\Actions\ChangePlan;
use Modules\Billing\Actions\StartTrial;
use Modules\Billing\Database\Seeders\PlanSeeder;
use Modules\Billing\Exceptions\BillingException;
use Modules\Billing\Gateways\FakeGateway;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\SubscriptionInvoice;
use Modules\Billing\Providers\BillingServiceProvider;
use Modules\Billing\Support\Entitlements;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Account;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * What the plan a workspace is on is allowed to change.
 *
 * The invariant the whole module exists for is the last test in this file: a
 * limit that is decided anywhere other than Entitlements is a limit that will
 * eventually disagree with the one next to it.
 */
final class BillingTest extends LedgerTestCase
{
    use RefreshDatabase;

    /**
     * Registers the module's provider for the test run.
     *
     * Billing is not listed in bootstrap/providers.php, which this module does
     * not own and must not edit. Once it is listed, providerIsLoaded() makes
     * this a no-op.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if (! $app->providerIsLoaded(BillingServiceProvider::class)) {
            $app->register(BillingServiceProvider::class);
        }

        return $app;
    }

    private static bool $billingTablesMigrated = false;

    protected function setUp(): void
    {
        if (! self::$billingTablesMigrated) {
            // The suite shares one in-memory database, migrated once by whichever
            // test class ran first — and that class had no reason to register
            // this provider, so the billing tables are missing. Asking for one
            // more migration run, from a class that does register it, is what
            // puts them there without touching bootstrap/providers.php.
            RefreshDatabaseState::$migrated = false;
            self::$billingTablesMigrated = true;
        }

        parent::setUp();

        app(PlanSeeder::class)->seed();

        $this->registerGatedRoutes();
    }

    // ------------------------------------------------------------- entitlements

    #[Test]
    public function a_workspace_without_a_subscription_is_on_the_free_plan(): void
    {
        $workspace = $this->workspace('nosub@example.test');

        $entitlements = Entitlements::forWorkspace($workspace);

        $this->assertSame('free', $entitlements->planCode);
        $this->assertFalse($entitlements->allows('ai'));
        $this->assertSame(1, $entitlements->limit('workspaces'));
        $this->assertSame(2, $entitlements->limit('accounts'));
    }

    #[Test]
    public function remaining_never_goes_negative_and_unlimited_stays_null(): void
    {
        $free = Entitlements::forPlan('free');
        $premium = Entitlements::forPlan('premium');

        $this->assertSame(1, $free->remaining('accounts', 1));
        $this->assertSame(0, $free->remaining('accounts', 2));
        $this->assertSame(0, $free->remaining('accounts', 9), 'Being over a limit is not negative headroom.');

        $this->assertNull($premium->limit('accounts'));
        $this->assertNull($premium->remaining('accounts', 400));
        $this->assertTrue($premium->permits('accounts', 400));
    }

    #[Test]
    public function an_unknown_feature_is_denied_rather_than_granted(): void
    {
        $this->assertFalse(Entitlements::forPlan('enterprise')->allows('teleportation'));
    }

    // ---------------------------------------------------------------- middleware

    #[Test]
    public function the_free_plan_blocks_ai_and_says_why(): void
    {
        [$user, $workspace] = $this->world('freeai@example.test');

        Sanctum::actingAs($user);

        $response = $this->get('/api/v1/testing/ai', $this->headers($workspace));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'plan_limit_reached');
        $response->assertJsonPath('error.details.plan', 'free');
        $response->assertJsonPath('error.details.feature', 'ai');
        $response->assertJsonPath('error.details.limit', 0);
    }

    #[Test]
    public function the_premium_plan_allows_ai(): void
    {
        [$user, $workspace] = $this->world('premiumai@example.test');
        $this->putOnPlan($workspace, 'premium');

        Sanctum::actingAs($user);

        $this->get('/api/v1/testing/ai', $this->headers($workspace))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function the_free_plan_blocks_a_third_workspace(): void
    {
        $user = $this->makeUser('thirdws@example.test');
        $first = $this->makeWorkspace($user, 'Personal');
        $this->makeWorkspace($user, 'Second');

        Sanctum::actingAs($user);

        $response = $this->post('/api/v1/testing/workspaces', [], $this->headers($first));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'plan_limit_reached');
        $response->assertJsonPath('error.details.plan', 'free');
        $response->assertJsonPath('error.details.feature', 'workspaces');
        $response->assertJsonPath('error.details.limit', 1);
        $response->assertJsonPath('error.details.used', 2);
    }

    #[Test]
    public function the_premium_plan_allows_another_workspace(): void
    {
        $user = $this->makeUser('premiumws@example.test');
        $first = $this->makeWorkspace($user, 'Personal');
        $this->makeWorkspace($user, 'Second');
        $this->putOnPlan($first, 'premium');

        Sanctum::actingAs($user);

        $this->post('/api/v1/testing/workspaces', [], $this->headers($first))->assertOk();
    }

    #[Test]
    public function the_free_plan_blocks_a_third_account(): void
    {
        [$user, $workspace] = $this->world('thirdacct@example.test');

        // The workspace is seeded with one account; this is the second.
        $this->makeAccount($workspace, 'Bank');

        Sanctum::actingAs($user);

        $response = $this->post('/api/v1/testing/accounts', [], $this->headers($workspace));

        $response->assertStatus(403);
        $response->assertJsonPath('error.details.feature', 'accounts');
        $response->assertJsonPath('error.details.limit', 2);
        $response->assertJsonPath('error.details.used', 2);
    }

    #[Test]
    public function the_refusal_body_follows_the_api_error_convention(): void
    {
        [$user, $workspace] = $this->world('body@example.test');

        Sanctum::actingAs($user);

        $response = $this->get('/api/v1/testing/ai', $this->headers($workspace));

        $response->assertStatus(403);
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'details' => ['plan', 'feature', 'limit'], 'request_id'],
        ]);
    }

    // --------------------------------------------------------------------- trial

    #[Test]
    public function a_trial_grants_the_plan_and_then_expires_back_to_free(): void
    {
        $workspace = $this->workspace('trial@example.test');

        $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00:00'));

        app(StartTrial::class)->handle($workspace, 'premium', days: 14);

        $this->assertSame('premium', Entitlements::forWorkspace($workspace)->planCode);
        $this->assertTrue(Entitlements::forWorkspace($workspace)->allows('ai'));

        $this->travelTo(CarbonImmutable::parse('2026-07-16 10:00:00'));

        $entitlements = Entitlements::forWorkspace($workspace);

        $this->assertSame('free', $entitlements->planCode, 'An expired trial must fall back to the default plan.');
        $this->assertFalse($entitlements->allows('ai'));

        // The row still remembers what was tried; only the entitlement lapsed.
        $subscription = Subscription::forWorkspaceId($workspace->id);
        $this->assertNotNull($subscription);
        $this->assertSame('premium', $subscription->plan_code);
    }

    #[Test]
    public function a_trial_can_only_be_taken_once(): void
    {
        $workspace = $this->workspace('trialonce@example.test');

        app(StartTrial::class)->handle($workspace, 'premium');

        $this->expectException(BillingException::class);

        app(StartTrial::class)->handle($workspace, 'family');
    }

    // ----------------------------------------------------------------- upgrades

    #[Test]
    public function an_upgrade_charges_the_gateway_and_leaves_a_paid_invoice(): void
    {
        $workspace = $this->workspace('upgrade@example.test');

        app(ChangePlan::class)->handle($workspace, 'premium');

        $gateway = app(FakeGateway::class);

        $this->assertCount(1, $gateway->charges());
        $this->assertSame(9_99, $gateway->charges()[0]['amount']);
        $this->assertSame('USD', $gateway->charges()[0]['currency']);

        $invoice = $this->inWorkspace($workspace, fn () => SubscriptionInvoice::query()->sole());

        $this->assertSame(SubscriptionInvoice::STATUS_PAID, $invoice->status);
        $this->assertSame(9_99, $invoice->amount);
        $this->assertSame('premium', $invoice->plan_code);
    }

    #[Test]
    public function a_declined_charge_leaves_a_failed_invoice_and_the_old_plan(): void
    {
        $workspace = $this->workspace('declined@example.test');

        app(FakeGateway::class)->declineWith('insufficient_funds');

        try {
            app(ChangePlan::class)->handle($workspace, 'premium');
            $this->fail('A declined charge must refuse the upgrade.');
        } catch (BillingException $e) {
            $this->assertSame('payment_failed', $e->errorCode);
        }

        $this->assertSame('free', Entitlements::forWorkspace($workspace)->planCode);

        $invoice = $this->inWorkspace($workspace, fn () => SubscriptionInvoice::query()->sole());

        $this->assertSame(SubscriptionInvoice::STATUS_FAILED, $invoice->status);
    }

    // --------------------------------------------------------------- downgrades

    #[Test]
    public function downgrading_below_current_usage_is_refused_with_a_reason(): void
    {
        $workspace = $this->workspace('downgrade@example.test');
        $this->putOnPlan($workspace, 'premium');

        // Seeded wallet plus four more: five accounts against a Free limit of two.
        foreach (['Bank', 'Card', 'Savings', 'Gold'] as $name) {
            $this->makeAccount($workspace, $name);
        }

        try {
            app(ChangePlan::class)->handle($workspace, 'free');
            $this->fail('A downgrade that does not fit must be refused.');
        } catch (BillingException $e) {
            $this->assertSame('downgrade_blocked', $e->errorCode);
            $this->assertSame(422, $e->status);
            $this->assertSame('premium', $e->details['from']);
            $this->assertSame('free', $e->details['to']);
            $this->assertContains(
                ['feature' => 'accounts', 'limit' => 2, 'used' => 5],
                $e->details['violations'],
            );
            $this->assertStringContainsString('5 accounts against a limit of 2', $e->getMessage());
        }

        $this->assertSame('premium', Entitlements::forWorkspace($workspace)->planCode);
        $this->assertSame(5, $this->inWorkspace($workspace, fn () => Account::query()->count()),
            'A refused downgrade must not delete anything to make room.');
    }

    #[Test]
    public function downgrading_is_allowed_once_usage_fits(): void
    {
        $workspace = $this->workspace('fits@example.test');
        $this->putOnPlan($workspace, 'premium');

        $extra = $this->makeAccount($workspace, 'Bank');
        $doomed = $this->makeAccount($workspace, 'Card');

        $this->inWorkspace($workspace, fn () => $doomed->forceDelete());

        $subscription = app(ChangePlan::class)->handle($workspace, 'free');

        $this->assertSame('free', $subscription->effectivePlanCode());
        $this->assertNotNull($extra->fresh(), 'Nothing else was touched.');
    }

    #[Test]
    public function cancelling_keeps_the_paid_period_and_then_falls_back(): void
    {
        $workspace = $this->workspace('cancel@example.test');

        $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00:00'));
        app(ChangePlan::class)->handle($workspace, 'premium');

        app(CancelSubscription::class)->handle($workspace);

        $this->assertSame('premium', Entitlements::forWorkspace($workspace)->planCode);

        $this->travelTo(CarbonImmutable::parse('2026-08-02 10:00:00'));

        $this->assertSame('free', Entitlements::forWorkspace($workspace)->planCode);
    }

    // ------------------------------------------------------------------ the API

    #[Test]
    public function the_subscription_endpoint_reports_the_plan_its_limits_and_the_usage(): void
    {
        [$user, $workspace] = $this->world('api@example.test');

        Sanctum::actingAs($user);

        $response = $this->get('/api/v1/billing/subscription', $this->headers($workspace));

        $response->assertOk();
        $response->assertJsonPath('data.effective_plan_code', 'free');
        $response->assertJsonPath('entitlements.plan', 'free');
        $response->assertJsonPath('entitlements.flags.ai', false);
        $response->assertJsonPath('entitlements.limits.accounts', 2);
        $response->assertJsonPath('usage.accounts', 1);
    }

    #[Test]
    public function the_plan_catalogue_lists_every_plan_with_an_integer_price(): void
    {
        [$user, $workspace] = $this->world('catalogue@example.test');

        Sanctum::actingAs($user);

        $response = $this->get('/api/v1/billing/plans', $this->headers($workspace));

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('data.0.code', 'free');
        $response->assertJsonPath('data.1.price.amount', 9_99);
        $this->assertIsInt($response->json('data.1.price.amount'));
    }

    // --------------------------------------------------------------- isolation

    #[Test]
    public function a_plan_bought_in_one_workspace_does_not_leak_into_another(): void
    {
        $user = $this->makeUser('isolation@example.test');
        $paid = $this->makeWorkspace($user, 'Company');
        $unpaid = $this->makeWorkspace($user, 'Personal');

        app(ChangePlan::class)->handle($paid, 'premium');

        $this->assertSame('premium', Entitlements::forWorkspace($paid)->planCode);
        $this->assertSame('free', Entitlements::forWorkspace($unpaid)->planCode);
        $this->assertTrue(Entitlements::forWorkspace($paid)->allows('ai'));
        $this->assertFalse(Entitlements::forWorkspace($unpaid)->allows('ai'));

        Sanctum::actingAs($user);

        $this->get('/api/v1/testing/ai', $this->headers($paid))->assertOk();
        $this->get('/api/v1/testing/ai', $this->headers($unpaid))->assertStatus(403);

        $this->get('/api/v1/billing/invoices', $this->headers($paid))->assertJsonCount(1, 'data');
        $this->get('/api/v1/billing/invoices', $this->headers($unpaid))->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_current_entitlements_follow_the_active_workspace(): void
    {
        $user = $this->makeUser('current@example.test');
        $paid = $this->makeWorkspace($user, 'Company');
        $unpaid = $this->makeWorkspace($user, 'Personal');

        app(ChangePlan::class)->handle($paid, 'family');

        $this->assertSame('family', $this->inWorkspace($paid, fn () => Entitlements::current()->planCode));
        $this->assertSame('free', $this->inWorkspace($unpaid, fn () => Entitlements::current()->planCode));
    }

    // ------------------------------------------------------------- architecture

    /**
     * The rule the module exists to enforce, checked against the source itself.
     *
     * A behavioural test cannot catch a second copy of a limit that happens to
     * agree with the first today; only reading the code can.
     */
    #[Test]
    public function every_limit_is_decided_in_entitlements_and_nowhere_else(): void
    {
        $sources = [
            'modules/Billing/Support/PlanRegistry.php',
            'modules/Billing/Support/Entitlements.php',
            'modules/Billing/Config/plans.php',
        ];

        $offenders = [];

        foreach ($this->modulePhpFiles() as $relative => $contents) {
            if (in_array($relative, $sources, true)) {
                continue;
            }

            if (preg_match("/config\(\s*['\"]plans\./", $contents) === 1) {
                $offenders[] = "{$relative} reads the plan catalogue directly.";
            }

            if (preg_match("/(?:plan_code|planCode|effectivePlanCode\(\))\s*[!=]==?\s*['\"]/", $contents) === 1) {
                $offenders[] = "{$relative} compares a plan code against a literal.";
            }

            if (preg_match('/PlanRegistry::(?:flags|limits)\(/', $contents) === 1) {
                $offenders[] = "{$relative} reads a plan's flags or limits itself.";
            }
        }

        $this->assertSame([], $offenders, implode(' ', $offenders));

        $middleware = file_get_contents(base_path('modules/Billing/Http/Middleware/RequiresEntitlement.php'));
        $this->assertIsString($middleware);

        $this->assertStringContainsString('Entitlements::current()', $middleware);
        $this->assertSame(
            0,
            preg_match('/[<>]=?\s*\d+/', $middleware),
            'The gate must not carry a number of its own.',
        );
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, string> relative path => contents */
    private function modulePhpFiles(): array
    {
        $root = base_path('modules');
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files['modules'.str_replace($root, '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    /**
     * Routes that exist only to exercise the gate.
     *
     * The features these stand for — AI, workspace creation — live in modules
     * this one must not edit, so the middleware is proven here against the same
     * alias any of them would use.
     */
    private function registerGatedRoutes(): void
    {
        Route::prefix('api/v1')
            ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
            ->group(function (): void {
                Route::get('testing/ai', fn () => response()->json(['ok' => true]))
                    ->middleware('entitlement:ai');

                Route::post('testing/workspaces', fn () => response()->json(['ok' => true]))
                    ->middleware('entitlement:workspaces');

                Route::post('testing/accounts', fn () => response()->json(['ok' => true]))
                    ->middleware('entitlement:accounts');
            });
    }

    private function workspace(string $email, string $name = 'Personal'): Workspace
    {
        return $this->makeWorkspace($this->makeUser($email), $name);
    }

    /** @return array{0: User, 1: Workspace} */
    private function world(string $email): array
    {
        $user = $this->makeUser($email);

        return [$user, $this->makeWorkspace($user, 'Personal')];
    }

    private function putOnPlan(Workspace $workspace, string $planCode): Subscription
    {
        return app(ChangePlan::class)->handle($workspace, $planCode);
    }

    /** @return array<string, string> */
    private function headers(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => $workspace->id, 'Accept' => 'application/json'];
    }
}
