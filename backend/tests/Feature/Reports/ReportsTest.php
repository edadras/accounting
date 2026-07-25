<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Reports\Actions\RebuildMonthlySummaries;
use Modules\Reports\Models\MonthlySummary;
use Modules\Reports\Providers\ReportsServiceProvider;
use Modules\Reports\Queries\CashFlowReport;
use Modules\Reports\Queries\CategoryBreakdownReport;
use Modules\Reports\Queries\ExpenseTrendReport;
use Modules\Reports\Queries\IncomeTrendReport;
use Modules\Reports\Queries\NetWorthReport;
use Modules\Reports\Queries\TopAccountsReport;
use Modules\Reports\Queries\TopCategoriesReport;
use Modules\Reports\Queries\TopMerchantsReport;
use Modules\Reports\Support\Bucket;
use Modules\Reports\Support\DateRange;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * Reports are the product's answer to "where did my money go". A report that is
 * subtly wrong is worse than one that is missing, because the user acts on it.
 */
final class ReportsTest extends LedgerTestCase
{
    use RefreshDatabase;

    /**
     * The Reports module owns its own migrations and routes, so the provider
     * has to be registered before RefreshDatabase migrates. Registering it here
     * keeps the suite honest whether or not the app has listed it yet.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $app->register(ReportsServiceProvider::class);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase migrates once for the whole run, using whichever test
        // class happened to go first. If that class did not have the Reports
        // provider registered, this module's tables are missing — so add them
        // here rather than depending on suite ordering.
        if (! Schema::hasTable('monthly_summaries')) {
            $this->artisan('migrate', ['--path' => 'modules/Reports/Database/Migrations']);
        }
    }

    #[Test]
    public function transfers_never_appear_as_income_or_expense(): void
    {
        $workspace = $this->workspaceWithBooks();

        $this->inWorkspace($workspace, function () use ($workspace): void {
            $range = DateRange::between('2026-03-01', '2026-03-31');

            $cashFlow = app(CashFlowReport::class)->handle($range, Bucket::Month);

            // 40,000 income and 15,000 expense were recorded; the 90,000
            // transfer between the user's own accounts is not either one.
            $this->assertSame(40000, $cashFlow['totals']['income']['value']);
            $this->assertSame(15000, $cashFlow['totals']['expense']['value']);
            $this->assertSame(25000, $cashFlow['totals']['net']['value']);
            $this->assertSame(2, $cashFlow['totals']['transaction_count']);

            $expenses = app(ExpenseTrendReport::class)->handle($range, Bucket::Month);
            $this->assertSame(15000, $expenses['totals']['total']['value']);
            $this->assertSame(1, $expenses['totals']['transaction_count']);

            $income = app(IncomeTrendReport::class)->handle($range, Bucket::Month);
            $this->assertSame(40000, $income['totals']['total']['value']);

            $categories = app(TopCategoriesReport::class)->handle($range, limit: 20, depth: 3);
            $this->assertSame(15000, $categories['totals']['total']['value']);
            $this->assertSame(1, $categories['totals']['transaction_count']);

            $accounts = app(TopAccountsReport::class)->handle($range, limit: 20);
            $this->assertSame(15000, $accounts['totals']['total']['value']);

            // Belt and braces: the transfer really is in the database.
            $this->assertSame(1, Transaction::query()->ofType('transfer')->count());
            $this->assertSame($workspace->id, $workspace->refresh()->id);
        });
    }

    #[Test]
    public function the_range_covers_both_boundary_days_in_full_and_nothing_outside_them(): void
    {
        // Contract under test (Modules\Reports\Support\DateRange):
        //   `from` is inclusive from 00:00:00 of that day;
        //   `to` is inclusive through the very end of that day.
        // So a transaction at 00:00:00 on `from` and one at 23:59:59 on `to`
        // are both counted, and the neighbouring days are not.
        $user = $this->makeUser('boundaries@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        $stamps = [
            '2026-05-09 23:59:59' => 1,      // day before `from`
            '2026-05-10 00:00:00' => 10,     // first instant of `from`
            '2026-05-10 12:00:00' => 100,
            '2026-05-12 23:59:59' => 1000,   // last instant of `to`
            '2026-05-13 00:00:00' => 10000,  // first instant after `to`
        ];

        $this->inWorkspace($workspace, function () use ($account, $stamps): void {
            foreach ($stamps as $when => $amount) {
                app(RecordTransaction::class)->handle([
                    'type' => 'expense',
                    'account_id' => $account->id,
                    'amount' => $amount,
                    'currency' => 'TRY',
                    'occurred_at' => $when,
                ]);
            }
        });

        $this->inWorkspace($workspace, function (): void {
            $range = DateRange::between('2026-05-10', '2026-05-12');
            $report = app(ExpenseTrendReport::class)->handle($range, Bucket::Day);

            $this->assertSame(1110, $report['totals']['total']['value']);
            $this->assertSame(3, $report['totals']['transaction_count']);

            // Boundaries are echoed back exactly as asked for.
            $this->assertSame('2026-05-10', $report['meta']['from']);
            $this->assertSame('2026-05-12', $report['meta']['to']);

            // Three day buckets, the middle one zero-filled rather than absent.
            $this->assertCount(3, $report['periods']);
            $this->assertSame(['2026-05-10', '2026-05-11', '2026-05-12'], array_column($report['periods'], 'key'));
            $this->assertSame(110, $report['periods'][0]['total']['value']);
            $this->assertSame(0, $report['periods'][1]['total']['value']);
            $this->assertSame(1000, $report['periods'][2]['total']['value']);
        });
    }

    #[Test]
    public function adjacent_ranges_count_every_transaction_exactly_once(): void
    {
        // The other half of the boundary contract: because `to` is inclusive
        // and the range is half-open underneath, [1–10] and [11–20] partition
        // the month with no gap and no overlap.
        $user = $this->makeUser('partition@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        $this->inWorkspace($workspace, function () use ($account): void {
            foreach (range(1, 20) as $day) {
                app(RecordTransaction::class)->handle([
                    'type' => 'expense',
                    'account_id' => $account->id,
                    'amount' => 100,
                    'currency' => 'TRY',
                    'occurred_at' => sprintf('2026-06-%02d 13:37:00', $day),
                ]);
            }
        });

        $this->inWorkspace($workspace, function (): void {
            $first = app(ExpenseTrendReport::class)
                ->handle(DateRange::between('2026-06-01', '2026-06-10'), Bucket::Month);
            $second = app(ExpenseTrendReport::class)
                ->handle(DateRange::between('2026-06-11', '2026-06-20'), Bucket::Month);
            $whole = app(ExpenseTrendReport::class)
                ->handle(DateRange::between('2026-06-01', '2026-06-20'), Bucket::Month);

            $this->assertSame(1000, $first['totals']['total']['value']);
            $this->assertSame(1000, $second['totals']['total']['value']);
            $this->assertSame(
                $whole['totals']['total']['value'],
                $first['totals']['total']['value'] + $second['totals']['total']['value'],
            );

            // A month bucket over a partial month reports the days requested,
            // not the whole month.
            $this->assertSame('2026-06-01', $first['periods'][0]['start']);
            $this->assertSame('2026-06-10', $first['periods'][0]['end']);
        });
    }

    #[Test]
    public function top_categories_rolls_a_subtree_into_its_parent_at_the_requested_depth(): void
    {
        $user = $this->makeUser('depth@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        $this->inWorkspace($workspace, function () use ($account): void {
            // The seeded tree gives /home/food/restaurant, /home/food/groceries
            // and /home/rent.
            $spend = [
                '/home/food/restaurant' => 5000,
                '/home/food/groceries' => 3000,
                '/home/rent' => 2000,
                '/transport/taxi' => 700,
            ];

            foreach ($spend as $path => $amount) {
                $category = Category::query()->where('path', $path)->firstOrFail();

                app(RecordTransaction::class)->handle([
                    'type' => 'expense',
                    'account_id' => $account->id,
                    'category_id' => $category->id,
                    'amount' => $amount,
                    'currency' => 'TRY',
                    'occurred_at' => '2026-04-15 10:00:00',
                ]);
            }
        });

        $range = DateRange::between('2026-04-01', '2026-04-30');

        $this->inWorkspace($workspace, function () use ($range): void {
            $depth1 = app(TopCategoriesReport::class)->handle($range, limit: 10, depth: 1);
            $byPath = array_column($depth1['categories'], null, 'path');

            // Everything under /home collapses into /home: 5000 + 3000 + 2000.
            $this->assertSame(10000, $byPath['/home']['total']['value']);
            $this->assertSame(3, $byPath['/home']['transaction_count']);
            $this->assertSame(700, $byPath['/transport']['total']['value']);
            $this->assertSame(1, $depth1['categories'][0]['rank']);
            $this->assertSame('/home', $depth1['categories'][0]['path']);

            $depth2 = app(TopCategoriesReport::class)->handle($range, limit: 10, depth: 2);
            $byPath = array_column($depth2['categories'], null, 'path');

            // The restaurant and groceries leaves roll into /home/food, while
            // /home/rent — already at depth 2 — stays where it is.
            $this->assertSame(8000, $byPath['/home/food']['total']['value']);
            $this->assertSame(2, $byPath['/home/food']['transaction_count']);
            $this->assertSame(2000, $byPath['/home/rent']['total']['value']);
            $this->assertArrayNotHasKey('/home/food/restaurant', $byPath);

            $depth3 = app(TopCategoriesReport::class)->handle($range, limit: 10, depth: 3);
            $byPath = array_column($depth3['categories'], null, 'path');

            $this->assertSame(5000, $byPath['/home/food/restaurant']['total']['value']);
            $this->assertSame(3000, $byPath['/home/food/groceries']['total']['value']);

            // Rolling up moves money between rows; it never creates or loses any.
            foreach ([$depth1, $depth2, $depth3] as $report) {
                $this->assertSame(10700, $report['totals']['total']['value']);
                $this->assertSame(4, $report['totals']['transaction_count']);
            }
        });
    }

    #[Test]
    public function a_limited_top_categories_list_keeps_the_remainder_in_other(): void
    {
        $workspace = $this->workspaceWithBooks();
        $range = DateRange::between('2026-03-01', '2026-03-31');

        $this->inWorkspace($workspace, function () use ($range): void {
            $report = app(TopCategoriesReport::class)->handle($range, limit: 1, depth: 3);

            $this->assertCount(1, $report['categories']);
            $this->assertSame(
                $report['totals']['total']['value'],
                $report['categories'][0]['total']['value'] + $report['other']['total']['value'],
            );
        });
    }

    #[Test]
    public function category_breakdown_percentages_add_up(): void
    {
        $user = $this->makeUser('pie@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        $this->inWorkspace($workspace, function () use ($account): void {
            foreach (['/home' => 7500, '/transport' => 2500] as $path => $amount) {
                $category = Category::query()->where('path', $path)->firstOrFail();

                app(RecordTransaction::class)->handle([
                    'type' => 'expense',
                    'account_id' => $account->id,
                    'category_id' => $category->id,
                    'amount' => $amount,
                    'currency' => 'TRY',
                    'occurred_at' => '2026-02-10 09:00:00',
                ]);
            }
        });

        $this->inWorkspace($workspace, function (): void {
            $report = app(CategoryBreakdownReport::class)
                ->handle(DateRange::between('2026-02-01', '2026-02-28'), limit: 10, depth: 1);

            $this->assertSame(10000, $report['totals']['total']['value']);
            $this->assertSame(75.0, $report['slices'][0]['percentage']);
            $this->assertSame(25.0, $report['slices'][1]['percentage']);
            $this->assertSame(100.0, array_sum(array_column($report['slices'], 'percentage')));
        });
    }

    #[Test]
    public function top_merchants_groups_by_payee(): void
    {
        $workspace = $this->workspaceWithBooks();

        $this->inWorkspace($workspace, function (): void {
            $report = app(TopMerchantsReport::class)
                ->handle(DateRange::between('2026-03-01', '2026-03-31'), limit: 5);

            $this->assertSame('Migros', $report['merchants'][0]['payee']);
            $this->assertSame(15000, $report['merchants'][0]['total']['value']);
            $this->assertSame(100.0, $report['merchants'][0]['percentage']);
        });
    }

    #[Test]
    public function net_worth_equals_the_sum_of_account_balances(): void
    {
        $user = $this->makeUser('worth@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $wallet = $this->makeAccount($workspace, 'Wallet', 'TRY', 500000);
        $bank = $this->makeAccount($workspace, 'Bank', 'TRY', 1_500_000);

        $this->inWorkspace($workspace, function () use ($wallet, $bank): void {
            $record = app(RecordTransaction::class);

            $record->handle([
                'type' => 'expense',
                'account_id' => $wallet->id,
                'amount' => 25000,
                'currency' => 'TRY',
                'occurred_at' => '2026-01-15 10:00:00',
            ]);

            $record->handle([
                'type' => 'income',
                'account_id' => $bank->id,
                'amount' => 300000,
                'currency' => 'TRY',
                'occurred_at' => '2026-02-15 10:00:00',
            ]);

            // A transfer moves money but must not change net worth.
            $record->handle([
                'type' => 'transfer',
                'account_id' => $bank->id,
                'counter_account_id' => $wallet->id,
                'amount' => 100000,
                'currency' => 'TRY',
                'occurred_at' => '2026-03-15 10:00:00',
            ]);
        });

        $this->inWorkspace($workspace, function (): void {
            $expected = Account::query()->sum('current_balance');

            $report = app(NetWorthReport::class)
                ->handle(DateRange::between('2026-01-01', '2026-03-31'), Bucket::Month);

            $this->assertSame((int) $expected, $report['total']['value']);
            $this->assertSame('TRY', $report['total']['currency']);

            // 500,000 + 1,500,000 opening, less 25,000 spent, plus 300,000 earned.
            $this->assertSame(2_275_000, $report['total']['value']);

            $series = array_column($report['series'], 'net_worth');
            $this->assertCount(3, $series);
            $this->assertSame(2_275_000 - 300_000, $series[0]['value']); // end of January
            $this->assertSame(2_275_000, $series[1]['value']);           // end of February
            $this->assertSame(2_275_000, $series[2]['value']);           // the transfer is a no-op
        });
    }

    #[Test]
    public function net_worth_converts_a_foreign_account_into_the_base_currency(): void
    {
        $user = $this->makeUser('fx@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'USD');
        $this->makeAccount($workspace, 'Lira wallet', 'TRY', 1_000_000); // ₺10,000
        $this->makeAccount($workspace, 'Dollar bank', 'USD', 5000);      // $50

        $this->inWorkspace($workspace, function (): void {
            $report = app(NetWorthReport::class)
                ->handle(DateRange::between('2026-01-01', '2026-01-31'), Bucket::Month);

            // ₺10,000 at the seeded 0.031 rate is $310, plus $50.
            $this->assertSame(36000, $report['total']['value']);
            $this->assertSame('USD', $report['total']['currency']);
        });
    }

    #[Test]
    public function an_empty_workspace_returns_zeros_rather_than_an_error(): void
    {
        $user = $this->makeUser('empty@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $range = DateRange::between('2026-07-01', '2026-07-31');

        $this->inWorkspace($workspace, function () use ($range): void {
            $cashFlow = app(CashFlowReport::class)->handle($range, Bucket::Month);
            $this->assertSame(0, $cashFlow['totals']['income']['value']);
            $this->assertSame(0, $cashFlow['totals']['expense']['value']);
            $this->assertSame(0, $cashFlow['totals']['net']['value']);
            $this->assertCount(1, $cashFlow['periods']);
            $this->assertSame(0, $cashFlow['periods'][0]['income']['value']);

            $this->assertSame(0, app(ExpenseTrendReport::class)->handle($range)['totals']['total']['value']);
            $this->assertSame(0, app(IncomeTrendReport::class)->handle($range)['totals']['total']['value']);

            $this->assertSame([], app(TopCategoriesReport::class)->handle($range)['categories']);
            $this->assertSame([], app(TopMerchantsReport::class)->handle($range)['merchants']);
            $this->assertSame([], app(TopAccountsReport::class)->handle($range)['accounts']);
            $this->assertSame([], app(CategoryBreakdownReport::class)->handle($range)['slices']);

            // The seeded starter wallet is empty, so net worth is a real zero.
            $netWorth = app(NetWorthReport::class)->handle($range, Bucket::Month);
            $this->assertSame(0, $netWorth['total']['value']);
            $this->assertSame('TRY', $netWorth['total']['currency']);
        });
    }

    #[Test]
    public function an_inverted_range_is_empty_rather_than_an_error(): void
    {
        $workspace = $this->workspaceWithBooks();

        $this->inWorkspace($workspace, function (): void {
            $range = DateRange::between('2026-03-31', '2026-03-01');
            $this->assertTrue($range->isEmpty());

            $report = app(CashFlowReport::class)->handle($range, Bucket::Month);

            $this->assertSame([], $report['periods']);
            $this->assertSame(0, $report['totals']['income']['value']);
            $this->assertSame(0, $report['totals']['expense']['value']);

            $trend = app(ExpenseTrendReport::class)->handle($range, Bucket::Day);
            $this->assertSame([], $trend['periods']);
            $this->assertSame(0, $trend['totals']['average_per_period']['value']);
        });
    }

    #[Test]
    public function reports_never_include_another_workspaces_transactions(): void
    {
        $victimWorkspace = $this->workspaceWithBooks('victim@example.test', 'Victim books');

        $intruder = $this->makeUser('nosy@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder books', 'TRY');
        $intruderAccount = $this->makeAccount($intruderWorkspace, 'Intruder wallet', 'TRY', 100000);

        $this->inWorkspace($intruderWorkspace, function () use ($intruderAccount): void {
            app(RecordTransaction::class)->handle([
                'type' => 'expense',
                'account_id' => $intruderAccount->id,
                'amount' => 700,
                'currency' => 'TRY',
                'payee' => 'Kebab',
                'occurred_at' => '2026-03-05 12:00:00',
            ]);
        });

        $range = DateRange::between('2026-03-01', '2026-03-31');

        $this->inWorkspace($intruderWorkspace, function () use ($range): void {
            $cashFlow = app(CashFlowReport::class)->handle($range, Bucket::Month);

            $this->assertSame(700, $cashFlow['totals']['expense']['value']);
            $this->assertSame(0, $cashFlow['totals']['income']['value']);

            $merchants = app(TopMerchantsReport::class)->handle($range, limit: 10);
            $this->assertSame(['Kebab'], array_column($merchants['merchants'], 'payee'));

            $netWorth = app(NetWorthReport::class)->handle($range, Bucket::Month);
            $this->assertSame(100000 - 700, $netWorth['total']['value']);
        });

        // And the victim's own numbers are untouched by the intruder's.
        $this->inWorkspace($victimWorkspace, function () use ($range): void {
            $cashFlow = app(CashFlowReport::class)->handle($range, Bucket::Month);

            $this->assertSame(15000, $cashFlow['totals']['expense']['value']);
            $this->assertSame(40000, $cashFlow['totals']['income']['value']);

            $merchants = app(TopMerchantsReport::class)->handle($range, limit: 10);
            $this->assertNotContains('Kebab', array_column($merchants['merchants'], 'payee'));
        });
    }

    #[Test]
    public function the_endpoint_serves_a_report_over_http(): void
    {
        $user = $this->makeUser('http@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 1_000_000);

        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 4200,
            'currency' => 'TRY',
            'occurred_at' => '2026-03-10 08:00:00',
        ]));

        Sanctum::actingAs($user);

        $response = $this->getJson(
            '/api/v1/reports/cash-flow?from=2026-03-01&to=2026-03-31&bucket=month',
            ['X-Workspace-Id' => $workspace->id],
        );

        $response->assertOk();
        $response->assertJsonPath('data.totals.expense.value', 4200);
        $response->assertJsonPath('data.totals.expense.currency', 'TRY');
        $response->assertJsonPath('data.totals.expense.minor_unit', 2);
        $response->assertJsonPath('data.totals.expense.decimal', '42.00');
        $response->assertJsonPath('data.meta.from', '2026-03-01');
    }

    #[Test]
    public function the_endpoint_refuses_a_report_type_that_is_not_on_the_whitelist(): void
    {
        $user = $this->makeUser('whitelist@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/reports/Modules%5CReports%5CQueries%5CCashFlowReport', [
            'X-Workspace-Id' => $workspace->id,
        ])->assertNotFound()->assertJsonPath('error.code', 'unknown_report');

        $this->getJson('/api/v1/reports/cash-flow?bucket=hour', [
            'X-Workspace-Id' => $workspace->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function the_endpoint_will_not_serve_another_workspaces_reports(): void
    {
        $victimWorkspace = $this->workspaceWithBooks('owner@example.test', 'Victim books');

        $intruder = $this->makeUser('outsider@example.test');
        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/reports/cash-flow', ['X-Workspace-Id' => $victimWorkspace->id])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'workspace_forbidden');
    }

    #[Test]
    public function the_monthly_cache_agrees_with_the_ledger_it_summarises(): void
    {
        $workspace = $this->workspaceWithBooks();

        $this->inWorkspace($workspace, function (): void {
            app(RebuildMonthlySummaries::class)->handle();

            $march = MonthlySummary::query()->forPeriod('2026-03')->workspaceGrain()->sole();

            $this->assertSame(40000, $march->income_amount);
            $this->assertSame(15000, $march->expense_amount);
            $this->assertSame(25000, $march->net()->minorUnits);
            $this->assertSame('TRY', $march->currency);
            $this->assertSame(2, $march->transaction_count);
            $this->assertNotNull($march->updated_at);

            // The cache is only ever a faster route to the same number.
            $live = app(CashFlowReport::class)
                ->handle(DateRange::between('2026-03-01', '2026-03-31'), Bucket::Month);

            $this->assertSame($live['totals']['net']['value'], $march->net()->minorUnits);

            // Rebuilding replaces a period instead of stacking duplicates.
            app(RebuildMonthlySummaries::class)->handle(['2026-03']);
            $this->assertSame(1, MonthlySummary::query()->forPeriod('2026-03')->workspaceGrain()->count());
        });
    }

    #[Test]
    public function the_monthly_cache_is_workspace_scoped(): void
    {
        $first = $this->workspaceWithBooks('cache-a@example.test', 'A');
        $second = $this->workspaceWithBooks('cache-b@example.test', 'B');

        $this->inWorkspace($first, fn () => app(RebuildMonthlySummaries::class)->handle());
        $this->inWorkspace($second, fn () => app(RebuildMonthlySummaries::class)->handle());

        foreach ([$first, $second] as $workspace) {
            $this->inWorkspace($workspace, function () use ($workspace): void {
                $rows = MonthlySummary::query()->get();

                $this->assertNotEmpty($rows);
                $this->assertSame([$workspace->id], $rows->pluck('workspace_id')->unique()->all());
            });
        }
    }

    /**
     * A workspace with one income, one expense (payee "Migros", category
     * /home/food/restaurant) and one transfer, all in March 2026.
     */
    private function workspaceWithBooks(
        string $email = 'books@example.test',
        string $name = 'Books',
    ): Workspace {
        $user = $this->makeUser($email);
        $workspace = $this->makeWorkspace($user, $name, 'TRY');
        $wallet = $this->makeAccount($workspace, 'Wallet', 'TRY', 200000);
        $bank = $this->makeAccount($workspace, 'Bank', 'TRY', 500000);

        $this->inWorkspace($workspace, function () use ($wallet, $bank): void {
            $record = app(RecordTransaction::class);
            $restaurant = Category::query()->where('path', '/home/food/restaurant')->firstOrFail();

            $record->handle([
                'type' => 'income',
                'account_id' => $bank->id,
                'amount' => 40000,
                'currency' => 'TRY',
                'occurred_at' => '2026-03-03 09:00:00',
            ]);

            $record->handle([
                'type' => 'expense',
                'account_id' => $wallet->id,
                'category_id' => $restaurant->id,
                'amount' => 15000,
                'currency' => 'TRY',
                'payee' => 'Migros',
                'occurred_at' => '2026-03-07 19:30:00',
            ]);

            $record->handle([
                'type' => 'transfer',
                'account_id' => $bank->id,
                'counter_account_id' => $wallet->id,
                'amount' => 90000,
                'currency' => 'TRY',
                'occurred_at' => '2026-03-20 11:00:00',
            ]);
        });

        return $workspace;
    }
}
