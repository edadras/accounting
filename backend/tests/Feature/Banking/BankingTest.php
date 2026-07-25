<?php

declare(strict_types=1);

namespace Tests\Feature\Banking;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Banking\Actions\ClearCheck;
use Modules\Banking\Actions\GenerateAmortizationSchedule;
use Modules\Banking\Actions\PayInstallment;
use Modules\Banking\Exceptions\BankingException;
use Modules\Banking\Models\Bank;
use Modules\Banking\Models\Check;
use Modules\Banking\Models\Loan;
use Modules\Banking\Models\LoanInstallment;
use Modules\Banking\Providers\BankingServiceProvider;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * The banking invariants.
 *
 * The one that matters most is the first: a schedule whose principal parts do
 * not add up to the principal leaves the borrower owing a phantom minor unit
 * forever, and no report can spot it.
 */
final class BankingTest extends LedgerTestCase
{
    use RefreshDatabase;

    /**
     * Registers the module's provider for the test run.
     *
     * Banking is not listed in bootstrap/providers.php, which this module does
     * not own and must not edit. Once it is listed, providerIsLoaded() makes
     * this a no-op.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if (! $app->providerIsLoaded(BankingServiceProvider::class)) {
            $app->register(BankingServiceProvider::class);
        }

        return $app;
    }

    // ---------------------------------------------------------------- schedule

    #[Test]
    public function the_principal_parts_of_a_schedule_sum_to_the_principal_exactly(): void
    {
        $user = $this->makeUser('amort@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Loan account', 'TRY');

        // Deliberately awkward: principals that do not divide, instalment counts
        // that are prime, and rates that produce endless decimals.
        $cases = [
            [100000, 7, '0', Loan::INTEREST_SIMPLE],
            [100000, 7, '18.5', Loan::INTEREST_SIMPLE],
            [100000, 7, '18.5', Loan::INTEREST_COMPOUND],
            [999999, 13, '0', Loan::INTEREST_SIMPLE],
            [999999, 13, '23.75', Loan::INTEREST_SIMPLE],
            [999999, 13, '23.75', Loan::INTEREST_COMPOUND],
            [1, 3, '12', Loan::INTEREST_COMPOUND],
            [7, 7, '33.333', Loan::INTEREST_COMPOUND],
            [123456789, 11, '7.125', Loan::INTEREST_COMPOUND],
            [500003, 3, '9.9', Loan::INTEREST_SIMPLE],
            [1000000, 360, '4.25', Loan::INTEREST_COMPOUND],
        ];

        $this->inWorkspace($workspace, function () use ($account, $cases): void {
            foreach ($cases as [$principal, $count, $rate, $type]) {
                $loan = $this->makeLoan($account, $principal, $count, $rate, $type);
                $installments = app(GenerateAmortizationSchedule::class)->handle($loan);

                $label = "{$type} {$principal}/{$count} @ {$rate}%";

                $this->assertCount($count, $installments, "Wrong instalment count for {$label}.");

                $this->assertSame(
                    $principal,
                    (int) $installments->sum(fn (LoanInstallment $row) => $row->principal_part),
                    "Principal parts do not sum to the principal for {$label}.",
                );

                foreach ($installments as $row) {
                    $this->assertGreaterThanOrEqual(0, $row->principal_part, "Negative principal part in {$label}.");
                    $this->assertGreaterThanOrEqual(0, $row->interest_part, "Negative interest part in {$label}.");
                    $this->assertSame(
                        $row->principal_part + $row->interest_part,
                        $row->total_amount,
                        "Instalment total is not its parts in {$label}.",
                    );
                }
            }
        });
    }

    #[Test]
    public function an_annuity_schedule_reduces_the_balance_to_exactly_zero(): void
    {
        $user = $this->makeUser('annuity@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Loan account', 'TRY');

        $this->inWorkspace($workspace, function () use ($account): void {
            foreach ([[100000, 7, '18.5'], [999999, 13, '23.75'], [2500000, 24, '11.4']] as [$principal, $count, $rate]) {
                $loan = $this->makeLoan($account, $principal, $count, $rate, Loan::INTEREST_COMPOUND);
                $rows = app(GenerateAmortizationSchedule::class)->rows($loan);

                $previous = $principal;

                foreach ($rows as $row) {
                    $this->assertLessThanOrEqual($previous, $row['closing_balance'], 'Balance went up.');
                    $this->assertGreaterThanOrEqual(0, $row['closing_balance'], 'Balance went negative.');
                    $previous = $row['closing_balance'];
                }

                $this->assertSame(
                    0,
                    $rows[array_key_last($rows)]['closing_balance'],
                    "Annuity {$principal}/{$count} @ {$rate}% did not close at zero.",
                );
            }
        });
    }

    #[Test]
    public function a_simple_interest_schedule_charges_the_flat_total_and_nothing_more(): void
    {
        $user = $this->makeUser('simple@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Loan account', 'TRY');

        $this->inWorkspace($workspace, function () use ($account): void {
            // ₺1,200.00 for a year at 12% flat is ₺144.00 of interest.
            $loan = $this->makeLoan($account, 120000, 12, '12', Loan::INTEREST_SIMPLE);
            $installments = app(GenerateAmortizationSchedule::class)->handle($loan);

            $this->assertSame(14400, (int) $installments->sum(fn (LoanInstallment $r) => $r->interest_part));
            $this->assertSame(120000, (int) $installments->sum(fn (LoanInstallment $r) => $r->principal_part));
            $this->assertSame(120000, $loan->fresh()->outstanding_balance);
        });
    }

    #[Test]
    public function regenerating_a_schedule_replaces_it_rather_than_adding_to_it(): void
    {
        $user = $this->makeUser('regen@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Loan account', 'TRY');

        $this->inWorkspace($workspace, function () use ($account): void {
            $loan = $this->makeLoan($account, 999999, 13, '23.75', Loan::INTEREST_COMPOUND);
            $generator = app(GenerateAmortizationSchedule::class);

            $generator->handle($loan);
            $generator->handle($loan);

            $rows = LoanInstallment::query()->where('loan_id', $loan->id)->get();

            $this->assertCount(13, $rows);
            $this->assertSame(999999, (int) $rows->sum(fn (LoanInstallment $r) => $r->principal_part));
        });
    }

    #[Test]
    public function a_loan_needs_at_least_one_instalment(): void
    {
        $user = $this->makeUser('zero-inst@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Loan account', 'TRY');

        $this->expectException(BankingException::class);

        $this->inWorkspace($workspace, function () use ($account): void {
            $loan = $this->makeLoan($account, 100000, 0, '5', Loan::INTEREST_SIMPLE);

            app(GenerateAmortizationSchedule::class)->rows($loan);
        });
    }

    // ------------------------------------------------------------------ cheques

    #[Test]
    public function clearing_a_received_cheque_posts_one_transaction_and_raises_the_balance(): void
    {
        $user = $this->makeUser('received@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 100000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $check = $this->makeCheck($account, Check::DIRECTION_RECEIVED, 45000);

            $check = app(ClearCheck::class)->handle($check);

            $this->assertSame(Check::STATUS_CLEARED, $check->status);
            $this->assertNotNull($check->transaction_id);
            $this->assertSame(1, Transaction::query()->count());
            $this->assertSame('income', Transaction::query()->first()->type);
            $this->assertSame(145000, $account->fresh()->current_balance);
        });
    }

    #[Test]
    public function clearing_an_issued_cheque_lowers_the_balance(): void
    {
        $user = $this->makeUser('issued@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 100000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $check = $this->makeCheck($account, Check::DIRECTION_ISSUED, 45000);

            app(ClearCheck::class)->handle($check);

            $this->assertSame(1, Transaction::query()->count());
            $this->assertSame('expense', Transaction::query()->first()->type);
            $this->assertSame(55000, $account->fresh()->current_balance);
        });
    }

    #[Test]
    public function clearing_an_already_cleared_cheque_is_a_no_op(): void
    {
        $user = $this->makeUser('twice@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 100000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $check = $this->makeCheck($account, Check::DIRECTION_RECEIVED, 45000);
            $clear = app(ClearCheck::class);

            $first = $clear->handle($check);
            $second = $clear->handle($first);

            $this->assertSame($first->transaction_id, $second->transaction_id);
            $this->assertSame(1, Transaction::query()->count(), 'Clearing twice posted twice.');
            $this->assertSame(145000, $account->fresh()->current_balance);
        });
    }

    #[Test]
    public function a_bounced_cheque_posts_nothing(): void
    {
        $user = $this->makeUser('bounced@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 100000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $check = $this->makeCheck($account, Check::DIRECTION_RECEIVED, 45000, Check::STATUS_BOUNCED);

            try {
                app(ClearCheck::class)->handle($check);
                $this->fail('A bounced cheque must not be clearable.');
            } catch (BankingException $e) {
                $this->assertSame('check_not_clearable', $e->errorCode);
            }

            $this->assertSame(0, Transaction::query()->count());
            $this->assertNull($check->fresh()->transaction_id);
            $this->assertSame(100000, $account->fresh()->current_balance);
        });
    }

    #[Test]
    public function a_void_cheque_cannot_be_cleared(): void
    {
        $user = $this->makeUser('void@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 100000);

        $this->expectException(BankingException::class);

        $this->inWorkspace($workspace, function () use ($account): void {
            app(ClearCheck::class)->handle(
                $this->makeCheck($account, Check::DIRECTION_ISSUED, 1000, Check::STATUS_VOID),
            );
        });
    }

    // -------------------------------------------------------------- instalments

    #[Test]
    public function a_partial_payment_leaves_the_instalment_partial_with_the_right_remainder(): void
    {
        $user = $this->makeUser('partial@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 1000000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $loan = $this->makeLoan($account, 120000, 12, '0', Loan::INTEREST_SIMPLE);
            app(GenerateAmortizationSchedule::class)->handle($loan);

            $first = $loan->installments()->first();
            $this->assertSame(10000, $first->total_amount);

            $first = app(PayInstallment::class)->handle($first, 4000);

            $this->assertSame(LoanInstallment::STATUS_PARTIAL, $first->status);
            $this->assertSame(4000, $first->paid_amount);
            $this->assertSame(6000, $first->remaining());
            $this->assertNull($first->paid_at);

            $this->assertSame(116000, $loan->fresh()->outstanding_balance);
            $this->assertSame(996000, $account->fresh()->current_balance);
            $this->assertSame(1, Transaction::query()->count());
        });
    }

    #[Test]
    public function paying_the_rest_of_a_part_paid_instalment_settles_it(): void
    {
        $user = $this->makeUser('settle@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 1000000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $loan = $this->makeLoan($account, 120000, 12, '0', Loan::INTEREST_SIMPLE);
            app(GenerateAmortizationSchedule::class)->handle($loan);

            $pay = app(PayInstallment::class);
            $first = $loan->installments()->first();

            $first = $pay->handle($first, 4000);
            $first = $pay->handle($first, 6000);

            $this->assertSame(LoanInstallment::STATUS_PAID, $first->status);
            $this->assertSame(0, $first->remaining());
            $this->assertNotNull($first->paid_at);
            $this->assertSame(110000, $loan->fresh()->outstanding_balance);
            $this->assertSame(2, Transaction::query()->count());
        });
    }

    #[Test]
    public function a_part_payment_of_an_interest_bearing_instalment_splits_without_losing_a_unit(): void
    {
        $user = $this->makeUser('split@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 10000000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $loan = $this->makeLoan($account, 999999, 13, '23.75', Loan::INTEREST_COMPOUND);
            app(GenerateAmortizationSchedule::class)->handle($loan);

            $first = $loan->installments()->first();
            $part = intdiv($first->total_amount, 2);

            $paid = app(PayInstallment::class)->handle($first, $part);

            $principalPaid = $paid->principalPaid('TRY')->minorUnits;

            $this->assertSame(LoanInstallment::STATUS_PARTIAL, $paid->status);
            $this->assertGreaterThan(0, $principalPaid);
            $this->assertLessThanOrEqual($paid->principal_part, $principalPaid);
            $this->assertSame(999999 - $principalPaid, $loan->fresh()->outstanding_balance);
        });
    }

    #[Test]
    public function paying_every_instalment_closes_the_loan_at_exactly_zero(): void
    {
        $user = $this->makeUser('closed@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 10000000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $loan = $this->makeLoan($account, 999999, 13, '23.75', Loan::INTEREST_COMPOUND);
            app(GenerateAmortizationSchedule::class)->handle($loan);

            $pay = app(PayInstallment::class);

            foreach ($loan->installments()->get() as $installment) {
                $pay->handle($installment, $installment->remaining());
            }

            $loan->refresh();

            $this->assertSame(0, $loan->outstanding_balance);
            $this->assertSame(Loan::STATUS_CLOSED, $loan->status);
            $this->assertSame(13, Transaction::query()->count());
        });
    }

    #[Test]
    public function an_instalment_cannot_be_overpaid(): void
    {
        $user = $this->makeUser('over@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 1000000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $loan = $this->makeLoan($account, 120000, 12, '0', Loan::INTEREST_SIMPLE);
            app(GenerateAmortizationSchedule::class)->handle($loan);

            try {
                app(PayInstallment::class)->handle($loan->installments()->first(), 10001);
                $this->fail('Overpaying an instalment must be refused.');
            } catch (BankingException $e) {
                $this->assertSame('installment_overpayment', $e->errorCode);
            }

            $this->assertSame(0, Transaction::query()->count());
            $this->assertSame(120000, $loan->fresh()->outstanding_balance);
        });
    }

    // -------------------------------------------------------------- isolation

    #[Test]
    public function cheques_never_leak_across_workspaces(): void
    {
        [$mine, $theirs] = $this->twoWorkspaces('cheque');

        $ours = $this->inWorkspace($mine, fn () => $this->makeCheck(
            $this->firstAccount($mine), Check::DIRECTION_RECEIVED, 1000,
        ));

        $foreign = $this->inWorkspace($theirs, fn () => $this->makeCheck(
            $this->firstAccount($theirs), Check::DIRECTION_ISSUED, 2000,
        ));

        $this->inWorkspace($mine, function () use ($ours, $foreign): void {
            $this->assertSame(1, Check::query()->count());
            $this->assertNotNull(Check::query()->find($ours->id));
            $this->assertNull(Check::query()->find($foreign->id), 'A cheque leaked across workspaces.');
        });

        $this->inWorkspace($theirs, function () use ($ours, $foreign): void {
            $this->assertSame(1, Check::query()->count());
            $this->assertNotNull(Check::query()->find($foreign->id));
            $this->assertNull(Check::query()->find($ours->id), 'A cheque leaked across workspaces.');
        });
    }

    #[Test]
    public function loans_banks_and_instalments_never_leak_across_workspaces(): void
    {
        [$mine, $theirs] = $this->twoWorkspaces('loan');

        $ours = $this->inWorkspace($mine, function () use ($mine) {
            $bank = Bank::query()->create(['name' => 'Bank of Mine']);
            $loan = $this->makeLoan($this->firstAccount($mine), 100000, 7, '18.5', Loan::INTEREST_COMPOUND, $bank);
            app(GenerateAmortizationSchedule::class)->handle($loan);

            return $loan;
        });

        $foreign = $this->inWorkspace($theirs, function () use ($theirs) {
            $bank = Bank::query()->create(['name' => 'Bank of Theirs']);
            $loan = $this->makeLoan($this->firstAccount($theirs), 999999, 13, '9', Loan::INTEREST_SIMPLE, $bank);
            app(GenerateAmortizationSchedule::class)->handle($loan);

            return $loan;
        });

        $this->inWorkspace($mine, function () use ($ours, $foreign): void {
            $this->assertSame(1, Loan::query()->count());
            $this->assertSame(1, Bank::query()->count());
            $this->assertSame(7, LoanInstallment::query()->count());
            $this->assertNotNull(Loan::query()->find($ours->id));
            $this->assertNull(Loan::query()->find($foreign->id), 'A loan leaked across workspaces.');
            $this->assertSame(0, LoanInstallment::query()->where('loan_id', $foreign->id)->count());
        });

        $this->inWorkspace($theirs, function () use ($ours): void {
            $this->assertSame(1, Loan::query()->count());
            $this->assertSame(13, LoanInstallment::query()->count());
            $this->assertNull(Loan::query()->find($ours->id), 'A loan leaked across workspaces.');
        });
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: Workspace, 1: Workspace} */
    private function twoWorkspaces(string $tag): array
    {
        return [
            $this->makeWorkspace($this->makeUser("{$tag}-mine@example.test"), 'Mine', 'TRY'),
            $this->makeWorkspace($this->makeUser("{$tag}-theirs@example.test"), 'Theirs', 'TRY'),
        ];
    }

    private function firstAccount(Workspace $workspace): Account
    {
        return $this->inWorkspace(
            $workspace,
            fn () => Account::query()->where('currency', 'TRY')->firstOrFail(),
        );
    }

    private function makeCheck(
        Account $account,
        string $direction,
        int $amount,
        string $status = Check::STATUS_IN_PROGRESS,
    ): Check {
        return Check::query()->create([
            'account_id' => $account->id,
            'direction' => $direction,
            'check_number' => (string) random_int(100000, 999999),
            'amount' => $amount,
            'currency' => $account->currency,
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => $status,
            'party_name' => 'Counterparty',
        ]);
    }

    private function makeLoan(
        Account $account,
        int $principal,
        int $count,
        string $rate,
        string $type,
        ?Bank $bank = null,
    ): Loan {
        return Loan::query()->create([
            'bank_id' => $bank?->id,
            'account_id' => $account->id,
            'title' => 'Loan',
            'principal' => $principal,
            'currency' => $account->currency,
            'interest_rate' => $rate,
            'interest_type' => $type,
            'installments_count' => $count,
            'start_date' => '2026-01-31',
            'penalty_rate' => '2',
            'outstanding_balance' => $principal,
            'status' => Loan::STATUS_ACTIVE,
        ]);
    }
}
