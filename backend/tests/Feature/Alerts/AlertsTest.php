<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Laravel\Sanctum\Sanctum;
use Modules\Alerts\Actions\DeliverDueAlerts;
use Modules\Alerts\Actions\ScanForAlerts;
use Modules\Alerts\Models\Alert;
use Modules\Alerts\Models\AlertPreference;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Models\ChannelKeys;
use Modules\Alerts\Providers\AlertsServiceProvider;
use Modules\Alerts\Support\DeliveryLog;
use Modules\Banking\Models\Check;
use Modules\Banking\Models\Loan;
use Modules\Banking\Models\LoanInstallment;
use Modules\Budget\Models\Budget;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * What the alert engine is allowed to tell a user, and how often.
 *
 * The hard part is not producing an alert — it is producing it once. A scan
 * that runs hourly sees the same cheque due for three days running; a user who
 * gets seventy-two reminders about it turns the whole feature off.
 */
final class AlertsTest extends LedgerTestCase
{
    use RefreshDatabase;

    /**
     * Registers the module's provider for the test run.
     *
     * Alerts is not listed in bootstrap/providers.php, which this module does
     * not own and must not edit. Once it is listed, providerIsLoaded() makes
     * this a no-op.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if (! $app->providerIsLoaded(AlertsServiceProvider::class)) {
            $app->register(AlertsServiceProvider::class);
        }

        return $app;
    }

    private static bool $alertTablesMigrated = false;

    protected function setUp(): void
    {
        if (! self::$alertTablesMigrated) {
            // The suite shares one in-memory database, migrated once by whichever
            // test class ran first — and that class had no reason to register
            // this provider, so the alert tables are missing. Asking for one more
            // migration run, from a class that does register it, is what puts
            // them there without touching bootstrap/providers.php.
            RefreshDatabaseState::$migrated = false;
            self::$alertTablesMigrated = true;
        }

        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00:00'));
    }

    // ------------------------------------------------------------------ cheques

    #[Test]
    public function a_cheque_due_in_three_days_produces_exactly_one_alert(): void
    {
        [, $workspace, $account] = $this->world('cheque@example.test');

        $this->makeCheck($workspace, $account, '2026-07-04');
        $this->makeCheck($workspace, $account, '2026-09-01');

        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE, ['lead_days' => 3]);

        $this->assertSame(1, $this->scan());

        $alert = $this->alerts($workspace)->sole();

        $this->assertSame(AlertRule::TYPE_CHECK_DUE, $alert->type);
        $this->assertSame('2026-07-04', $alert->payload['due_date']);
        $this->assertSame(500_00, $alert->payload['amount']['amount'], 'Money travels as minor units.');
        $this->assertSame(Alert::STATUS_SENT, $alert->status);
    }

    #[Test]
    public function running_the_scan_twice_produces_no_duplicate(): void
    {
        [, $workspace, $account] = $this->world('twice@example.test');

        $this->makeCheck($workspace, $account, '2026-07-04');
        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE, ['lead_days' => 3]);

        $this->assertSame(1, $this->scan());
        $this->assertSame(0, $this->scan(), 'The second scan must find nothing new.');

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));
        $this->assertSame(0, $this->scan(), 'Nor the next day, while it is still the same cheque.');

        $this->assertCount(1, $this->alerts($workspace)->get());
        $this->assertSame(1, app(DeliveryLog::class)->countFor(ChannelKeys::DATABASE));
    }

    #[Test]
    public function a_cleared_cheque_produces_nothing(): void
    {
        [, $workspace, $account] = $this->world('cleared@example.test');

        $this->makeCheck($workspace, $account, '2026-07-04', Check::STATUS_CLEARED);
        $this->makeCheck($workspace, $account, '2026-07-04', Check::STATUS_BOUNCED);
        $this->makeCheck($workspace, $account, '2026-07-04', Check::STATUS_VOID);

        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE, ['lead_days' => 3]);

        $this->assertSame(0, $this->scan());
        $this->assertCount(0, $this->alerts($workspace)->get());
    }

    #[Test]
    public function moving_a_cheque_to_a_new_date_is_worth_saying_again(): void
    {
        [, $workspace, $account] = $this->world('moved@example.test');

        $check = $this->makeCheck($workspace, $account, '2026-07-04');
        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE, ['lead_days' => 3]);

        $this->assertSame(1, $this->scan());

        $this->inWorkspace($workspace, fn () => $check->forceFill(['due_date' => '2026-07-03'])->save());

        $this->assertSame(1, $this->scan());
        $this->assertCount(2, $this->alerts($workspace)->get());
    }

    // ---------------------------------------------------------------- loans

    #[Test]
    public function a_loan_instalment_falling_due_produces_one_alert(): void
    {
        [, $workspace, $account] = $this->world('loan@example.test');

        $installment = $this->makeInstallment($workspace, $account, '2026-07-03');
        $this->makeInstallment($workspace, $account, '2026-07-03', LoanInstallment::STATUS_PAID);

        $this->makeRule($workspace, AlertRule::TYPE_INSTALLMENT_DUE, ['lead_days' => 5]);

        $this->assertSame(1, $this->scan());
        $this->assertSame(0, $this->scan());

        $alert = $this->alerts($workspace)->sole();

        $this->assertSame($installment->id, $alert->payload['installment_id']);
        $this->assertSame(1_000_00, $alert->payload['remaining']['amount']);
    }

    // --------------------------------------------------------------- budgets

    #[Test]
    public function a_budget_crossing_eighty_then_one_hundred_alerts_once_per_threshold(): void
    {
        [, $workspace, $account] = $this->world('budget@example.test');

        $this->makeBudget($workspace);
        $this->makeRule($workspace, AlertRule::TYPE_BUDGET_THRESHOLD);

        $this->spend($workspace, $account, 80_00, '2026-07-02 10:00:00');

        $this->assertSame(1, $this->scan());
        $this->assertSame(0, $this->scan(), 'The same threshold does not fire again.');

        $this->spend($workspace, $account, 20_00, '2026-07-03 10:00:00');

        $this->assertSame(1, $this->scan(), 'Crossing 100% is new news.');
        $this->assertSame(0, $this->scan());

        $thresholds = $this->alerts($workspace)->get()->pluck('payload.threshold')->all();

        $this->assertSame([80, 100], $thresholds);
    }

    #[Test]
    public function a_budget_below_its_lowest_threshold_says_nothing(): void
    {
        [, $workspace, $account] = $this->world('quietbudget@example.test');

        $this->makeBudget($workspace);
        $this->makeRule($workspace, AlertRule::TYPE_BUDGET_THRESHOLD);

        $this->spend($workspace, $account, 79_99, '2026-07-02 10:00:00');

        $this->assertSame(0, $this->scan());
    }

    // ---------------------------------------------------------- low balance

    #[Test]
    public function an_account_under_its_floor_produces_one_alert_a_day(): void
    {
        [, $workspace] = $this->world('lowbalance@example.test');

        $this->makeAccount($workspace, 'Thin', 'TRY', 10_00);

        $this->makeRule($workspace, AlertRule::TYPE_LOW_BALANCE, [
            'config' => ['threshold' => 50_00],
        ]);

        // The seeded wallet is on zero, so it is under the floor as well.
        $this->assertSame(2, $this->scan());
        $this->assertSame(0, $this->scan());

        $this->travelTo(CarbonImmutable::parse('2026-07-02 09:00:00'));

        $this->assertSame(2, $this->scan(), 'A balance that is still low is worth repeating tomorrow.');
    }

    // -------------------------------------------------------------- channels

    #[Test]
    public function dispatch_is_recorded_for_every_channel_the_rule_names(): void
    {
        [, $workspace, $account] = $this->world('channels@example.test');

        $this->makeCheck($workspace, $account, '2026-07-04');
        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE, [
            'channels' => [ChannelKeys::PUSH, ChannelKeys::EMAIL, ChannelKeys::TELEGRAM],
        ]);

        $this->scan();

        $alert = $this->alerts($workspace)->sole();

        $this->assertSame([
            ChannelKeys::PUSH => Alert::DELIVERY_SENT,
            ChannelKeys::EMAIL => Alert::DELIVERY_SENT,
            ChannelKeys::TELEGRAM => Alert::DELIVERY_SENT,
            ChannelKeys::DATABASE => Alert::DELIVERY_SENT,
        ], $alert->deliveries());

        $log = app(DeliveryLog::class);

        foreach ([ChannelKeys::PUSH, ChannelKeys::EMAIL, ChannelKeys::TELEGRAM, ChannelKeys::DATABASE] as $channel) {
            $this->assertSame(1, $log->countFor($channel), "Expected one delivery on {$channel}.");
        }

        $this->assertSame(0, $log->countFor(ChannelKeys::SMS));
    }

    #[Test]
    public function a_channel_the_member_switched_off_gets_nothing(): void
    {
        [$user, $workspace, $account] = $this->world('muted@example.test');

        $this->preference($workspace, $user, ['channels' => [ChannelKeys::SMS => false]]);

        $this->makeCheck($workspace, $account, '2026-07-04');
        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE, [
            'channels' => [ChannelKeys::SMS, ChannelKeys::PUSH],
        ]);

        $this->scan();

        $alert = $this->alerts($workspace)->sole();

        $this->assertArrayNotHasKey(ChannelKeys::SMS, $alert->deliveries());
        $this->assertTrue($alert->wasDeliveredOn(ChannelKeys::PUSH));
        $this->assertSame(0, app(DeliveryLog::class)->countFor(ChannelKeys::SMS));

        // The in-app record survives whatever the member muted: it is the alert.
        $this->assertTrue($alert->wasDeliveredOn(ChannelKeys::DATABASE));
    }

    // ----------------------------------------------------------- quiet hours

    #[Test]
    public function an_alert_inside_quiet_hours_is_deferred_and_not_dropped(): void
    {
        [$user, $workspace, $account] = $this->world('quiet@example.test');

        $this->preference($workspace, $user, [
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
            'timezone' => 'UTC',
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-07-01 23:30:00'));

        $this->makeCheck($workspace, $account, '2026-07-04');
        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE, ['channels' => [ChannelKeys::PUSH]]);

        $this->assertSame(1, $this->scan());

        $alert = $this->alerts($workspace)->sole();

        $this->assertSame(Alert::STATUS_PENDING, $alert->status);
        $this->assertNull($alert->sent_at);
        $this->assertTrue($alert->isDeferred());
        $this->assertSame('2026-07-02T07:00:00+00:00', $alert->scheduled_at->toIso8601String());
        $this->assertSame(0, app(DeliveryLog::class)->countFor(ChannelKeys::PUSH));

        $this->travelTo(CarbonImmutable::parse('2026-07-02 07:05:00'));

        $this->assertSame(1, app()->call([new DeliverDueAlerts, 'handle']));

        $alert->refresh();

        $this->assertSame(Alert::STATUS_SENT, $alert->status);
        $this->assertNotNull($alert->sent_at);
        $this->assertSame(1, app(DeliveryLog::class)->countFor(ChannelKeys::PUSH));
    }

    #[Test]
    public function an_alert_outside_quiet_hours_goes_straight_out(): void
    {
        [$user, $workspace, $account] = $this->world('awake@example.test');

        $this->preference($workspace, $user, [
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '07:00',
            'timezone' => 'UTC',
        ]);

        $this->makeCheck($workspace, $account, '2026-07-04');
        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE, ['channels' => [ChannelKeys::PUSH]]);

        $this->scan();

        $alert = $this->alerts($workspace)->sole();

        $this->assertSame(Alert::STATUS_SENT, $alert->status);
        $this->assertSame('2026-07-01T09:00:00+00:00', $alert->scheduled_at->toIso8601String());
    }

    // ------------------------------------------------------------- isolation

    #[Test]
    public function a_scan_never_crosses_a_workspace_boundary(): void
    {
        [$mine, $workspaceA, $accountA] = $this->world('a@example.test');
        [$theirs, $workspaceB, $accountB] = $this->world('b@example.test');

        $this->makeCheck($workspaceA, $accountA, '2026-07-04');
        $this->makeCheck($workspaceB, $accountB, '2026-07-04');

        $this->makeRule($workspaceA, AlertRule::TYPE_CHECK_DUE);
        $this->makeRule($workspaceB, AlertRule::TYPE_CHECK_DUE);

        $this->assertSame(2, $this->scan());

        $this->assertCount(1, $this->alerts($workspaceA)->get());
        $this->assertCount(1, $this->alerts($workspaceB)->get());

        $this->assertSame(
            $mine->id,
            (int) $this->alerts($workspaceA)->sole()->user_id,
            'Each workspace owner hears only about their own books.',
        );

        Sanctum::actingAs($mine);

        $response = $this->get('/api/v1/alerts', ['X-Workspace-Id' => $workspaceA->id, 'Accept' => 'application/json']);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');

        // A member of one workspace cannot read another's alerts even by asking.
        $this->get('/api/v1/alerts', ['X-Workspace-Id' => $workspaceB->id, 'Accept' => 'application/json'])
            ->assertStatus(403);

        $this->assertNotSame($theirs->id, $mine->id);
    }

    #[Test]
    public function scanning_one_workspace_leaves_the_others_alone(): void
    {
        [, $workspaceA, $accountA] = $this->world('only-a@example.test');
        [, $workspaceB, $accountB] = $this->world('only-b@example.test');

        $this->makeCheck($workspaceA, $accountA, '2026-07-04');
        $this->makeCheck($workspaceB, $accountB, '2026-07-04');
        $this->makeRule($workspaceA, AlertRule::TYPE_CHECK_DUE);
        $this->makeRule($workspaceB, AlertRule::TYPE_CHECK_DUE);

        $this->assertSame(1, $this->scan($workspaceA));

        $this->assertCount(1, $this->alerts($workspaceA)->get());
        $this->assertCount(0, $this->alerts($workspaceB)->get());
    }

    #[Test]
    public function an_inactive_rule_produces_nothing(): void
    {
        [, $workspace, $account] = $this->world('inactive@example.test');

        $this->makeCheck($workspace, $account, '2026-07-04');
        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE, ['is_active' => false]);

        $this->assertSame(0, $this->scan());
    }

    // --------------------------------------------------------------- the API

    #[Test]
    public function a_member_can_read_and_mute_their_own_channels(): void
    {
        [$user, $workspace] = $this->world('prefs@example.test');

        Sanctum::actingAs($user);
        $headers = ['X-Workspace-Id' => $workspace->id, 'Accept' => 'application/json'];

        $this->get('/api/v1/alerts/preferences', $headers)
            ->assertOk()
            ->assertJsonPath('data.channels.sms', true);

        $this->put('/api/v1/alerts/preferences', [
            'channels' => [ChannelKeys::SMS => false],
            'quiet_hours_start' => '23:00',
            'quiet_hours_end' => '06:30',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.channels.sms', false)
            ->assertJsonPath('data.channels.database', true)
            ->assertJsonPath('data.quiet_hours_start', '23:00');
    }

    #[Test]
    public function an_alert_can_be_marked_read(): void
    {
        [$user, $workspace, $account] = $this->world('read@example.test');

        $this->makeCheck($workspace, $account, '2026-07-04');
        $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE);
        $this->scan();

        $alert = $this->alerts($workspace)->sole();

        Sanctum::actingAs($user);
        $headers = ['X-Workspace-Id' => $workspace->id, 'Accept' => 'application/json'];

        $this->post("/api/v1/alerts/{$alert->id}/read", [], $headers)->assertOk();

        $this->get('/api/v1/alerts?unread=1', $headers)->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------------ helpers

    private function scan(?Workspace $workspace = null): int
    {
        /** @var int */
        return app()->call([new ScanForAlerts($workspace?->id), 'handle']);
    }

    /** @return Builder<Alert> */
    private function alerts(Workspace $workspace): Builder
    {
        return Alert::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    #[Test]
    public function a_rule_is_edited_in_place_rather_than_replaced(): void
    {
        [$user, $workspace] = $this->world('rule-patch@example.test');
        $rule = $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE);

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/alerts/rules/{$rule->id}", [
            'lead_days' => 7,
            'is_active' => false,
        ], ['X-Workspace-Id' => $workspace->id])
            ->assertOk()
            ->assertJsonPath('data.id', $rule->id)
            ->assertJsonPath('data.lead_days', 7)
            ->assertJsonPath('data.is_active', false);

        // The point of the endpoint: there was no way to edit a rule at all,
        // not even to pause one, so the client had to delete and recreate —
        // and a failure halfway through simply lost the rule.
        $this->assertSame(1, AlertRule::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function a_rule_cannot_change_type(): void
    {
        [$user, $workspace] = $this->world('rule-type@example.test');
        $rule = $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE);

        Sanctum::actingAs($user);

        // `type` decides which scanner reads the rule and therefore what its
        // config even means, so switching it reinterprets the stored config
        // instead of editing it.
        $this->patchJson("/api/v1/alerts/rules/{$rule->id}", [
            'type' => AlertRule::TYPE_LOW_BALANCE,
        ], ['X-Workspace-Id' => $workspace->id])->assertStatus(422);
    }

    #[Test]
    public function another_workspace_cannot_edit_a_rule(): void
    {
        [, $workspace] = $this->world('rule-victim@example.test');
        $rule = $this->makeRule($workspace, AlertRule::TYPE_CHECK_DUE);

        $intruder = $this->makeUser('rule-intruder@example.test');
        $theirs = $this->makeWorkspace($intruder, 'Theirs');

        app(WorkspaceContext::class)->forget();
        Sanctum::actingAs($intruder);

        $this->patchJson("/api/v1/alerts/rules/{$rule->id}", ['lead_days' => 1], [
            'X-Workspace-Id' => $theirs->id,
        ])->assertNotFound();
    }

    /** @return array{0: User, 1: Workspace, 2: Account} */
    private function world(string $email): array
    {
        $user = $this->makeUser($email);
        $workspace = $this->makeWorkspace($user, 'Personal');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 1_000_000_00);

        return [$user, $workspace, $account];
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeRule(Workspace $workspace, string $type, array $attributes = []): AlertRule
    {
        return $this->inWorkspace($workspace, fn () => AlertRule::query()->create(array_merge([
            'type' => $type,
            'config' => null,
            'channels' => [ChannelKeys::DATABASE],
            'lead_days' => 3,
            'is_active' => true,
        ], $attributes)));
    }

    private function makeCheck(
        Workspace $workspace,
        Account $account,
        string $dueDate,
        string $status = Check::STATUS_ISSUED,
    ): Check {
        return $this->inWorkspace($workspace, fn () => Check::query()->create([
            'account_id' => $account->id,
            'direction' => Check::DIRECTION_ISSUED,
            'check_number' => (string) random_int(100000, 999999),
            'amount' => 500_00,
            'currency' => 'TRY',
            'base_amount' => 500_00,
            'due_date' => $dueDate,
            'status' => $status,
            'party_name' => 'Landlord',
        ]));
    }

    private function makeInstallment(
        Workspace $workspace,
        Account $account,
        string $dueDate,
        string $status = LoanInstallment::STATUS_DUE,
    ): LoanInstallment {
        return $this->inWorkspace($workspace, function () use ($account, $dueDate, $status) {
            $loan = Loan::query()->create([
                'account_id' => $account->id,
                'title' => 'Car loan',
                'principal' => 12_000_00,
                'currency' => 'TRY',
                'interest_rate' => '0',
                'interest_type' => Loan::INTEREST_SIMPLE,
                'installments_count' => 12,
                'start_date' => '2026-07-01',
                'outstanding_balance' => 12_000_00,
                'status' => Loan::STATUS_ACTIVE,
            ]);

            return LoanInstallment::query()->create([
                'loan_id' => $loan->id,
                'number' => 1,
                'due_date' => $dueDate,
                'principal_part' => 1_000_00,
                'interest_part' => 0,
                'total_amount' => 1_000_00,
                'paid_amount' => $status === LoanInstallment::STATUS_PAID ? 1_000_00 : 0,
                'status' => $status,
            ]);
        });
    }

    private function makeBudget(Workspace $workspace): Budget
    {
        return $this->inWorkspace($workspace, fn () => Budget::query()->create([
            'name' => 'Monthly budget',
            'scope' => Budget::SCOPE_OVERALL,
            'period' => Budget::PERIOD_MONTHLY,
            'starts_at' => '2026-07-01 00:00:00',
            'amount' => 100_00,
            'currency' => 'TRY',
        ]));
    }

    /** @param  array<string, mixed>  $attributes */
    private function preference(Workspace $workspace, User $user, array $attributes): AlertPreference
    {
        return $this->inWorkspace($workspace, fn () => AlertPreference::query()->create(array_merge([
            'user_id' => $user->id,
        ], $attributes)));
    }

    private function spend(Workspace $workspace, Account $account, int $amount, string $occurredAt): void
    {
        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => 'TRY',
            'occurred_at' => $occurredAt,
        ]));
    }
}
