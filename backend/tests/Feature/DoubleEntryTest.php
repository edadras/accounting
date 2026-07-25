<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Exceptions\LedgerException;
use Modules\Ledger\Models\Entry;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ledger invariants. If any of these break, every report in the product is
 * lying, so none of them may ever be deleted or skipped.
 */
final class DoubleEntryTest extends LedgerTestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_expense_credits_the_account_and_lowers_the_balance(): void
    {
        $user = $this->makeUser('ali@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, openingBalance: 100000);
        $food = $this->makeCategory($workspace, 'Food');

        $transaction = $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'category_id' => $food->id,
            'amount' => 35000,
            'currency' => 'TRY',
            'description' => 'Dinner',
        ]));

        $this->assertSame('expense', $transaction->type);
        $this->assertCount(1, $transaction->entries);
        $this->assertSame(Entry::CREDIT, $transaction->entries->first()->direction);
        $this->assertSame(65000, $account->fresh()->current_balance);
    }

    #[Test]
    public function income_debits_the_account_and_raises_the_balance(): void
    {
        $user = $this->makeUser('mina@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, openingBalance: 0);

        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'income',
            'account_id' => $account->id,
            'amount' => 500000,
            'currency' => 'TRY',
        ]));

        $this->assertSame(500000, $account->fresh()->current_balance);
    }

    #[Test]
    public function a_same_currency_transfer_moves_money_and_balances_to_zero(): void
    {
        $user = $this->makeUser('reza@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $from = $this->makeAccount($workspace, 'Bank', 'TRY', 200000);
        $to = $this->makeAccount($workspace, 'Wallet 2', 'TRY', 0);

        $transaction = $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'transfer',
            'account_id' => $from->id,
            'counter_account_id' => $to->id,
            'amount' => 50000,
            'currency' => 'TRY',
        ]));

        $this->assertSame(150000, $from->fresh()->current_balance);
        $this->assertSame(50000, $to->fresh()->current_balance);

        $net = $transaction->entries->sum(fn (Entry $entry) => $entry->signedBaseAmount());
        $this->assertSame(0, $net, 'A same-currency transfer must net to zero in base currency.');
    }

    #[Test]
    public function a_cross_currency_transfer_credits_and_debits_in_each_accounts_own_currency(): void
    {
        $user = $this->makeUser('kaveh@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'USD');
        $lira = $this->makeAccount($workspace, 'Turkish bank', 'TRY', 1000000); // ₺10,000
        $dollars = $this->makeAccount($workspace, 'USD account', 'USD', 0);

        $transaction = $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'transfer',
            'account_id' => $lira->id,
            'counter_account_id' => $dollars->id,
            'amount' => 100000, // ₺1,000
            'currency' => 'TRY',
        ]));

        $entries = $transaction->entries->keyBy('account_id');

        $this->assertSame('TRY', $entries[$lira->id]->currency);
        $this->assertSame('USD', $entries[$dollars->id]->currency);

        // ₺1,000 at the seeded 0.031 rate is $31.00 = 3100 minor units.
        $this->assertSame(3100, $entries[$dollars->id]->amount);
        $this->assertSame(900000, $lira->fresh()->current_balance);
        $this->assertSame(3100, $dollars->fresh()->current_balance);
    }

    #[Test]
    public function every_transaction_balances_across_a_busy_workspace(): void
    {
        $user = $this->makeUser('books@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $wallet = $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);
        $bank = $this->makeAccount($workspace, 'Bank', 'TRY', 10_000_000);
        $category = $this->makeCategory($workspace, 'Misc');

        $this->inWorkspace($workspace, function () use ($wallet, $bank, $category): void {
            $record = app(RecordTransaction::class);

            for ($i = 1; $i <= 120; $i++) {
                $type = match ($i % 3) {
                    0 => 'transfer',
                    1 => 'expense',
                    default => 'income',
                };

                $record->handle([
                    'type' => $type,
                    'account_id' => $i % 2 === 0 ? $wallet->id : $bank->id,
                    'counter_account_id' => $type === 'transfer'
                        ? ($i % 2 === 0 ? $bank->id : $wallet->id)
                        : null,
                    'category_id' => $type === 'transfer' ? null : $category->id,
                    'amount' => 1000 + ($i * 7),
                    'currency' => 'TRY',
                ]);
            }
        });

        $this->inWorkspace($workspace, function () use ($wallet, $bank): void {
            // Invariant 1: every transfer nets to zero in base currency.
            $transfers = Transaction::query()->ofType('transfer')->with('entries')->get();
            $this->assertCount(40, $transfers);

            foreach ($transfers as $transfer) {
                $this->assertSame(
                    0,
                    $transfer->entries->sum(fn (Entry $e) => $e->signedBaseAmount()),
                    "Transfer {$transfer->id} does not balance.",
                );
            }

            // Invariant 2: every transaction produced at least one entry, and
            // transfers produced exactly two.
            foreach (Transaction::query()->with('entries')->get() as $transaction) {
                $expected = $transaction->type === 'transfer' ? 2 : 1;
                $this->assertCount($expected, $transaction->entries);
            }

            // Invariant 3: the cached balance still equals the entries behind it.
            foreach ([$wallet, $bank] as $account) {
                $cached = $account->fresh()->current_balance;
                $recomputed = $account->fresh()->recalculateBalance()->minorUnits;

                $this->assertSame($cached, $recomputed, "Cached balance drifted on {$account->name}.");
            }
        });
    }

    #[Test]
    public function it_refuses_an_amount_whose_currency_is_not_the_accounts(): void
    {
        $user = $this->makeUser('mismatch@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Lira wallet', 'TRY');

        $this->expectException(LedgerException::class);

        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 1000,
            'currency' => 'USD',
        ]));
    }

    #[Test]
    public function it_refuses_a_negative_amount_because_direction_lives_in_the_type(): void
    {
        $user = $this->makeUser('negative@example.test');
        $workspace = $this->makeWorkspace($user);
        $account = $this->makeAccount($workspace);

        $this->expectException(LedgerException::class);

        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => -1000,
            'currency' => 'TRY',
        ]));
    }

    #[Test]
    public function it_refuses_a_transfer_to_the_same_account(): void
    {
        $user = $this->makeUser('self@example.test');
        $workspace = $this->makeWorkspace($user);
        $account = $this->makeAccount($workspace);

        $this->expectException(LedgerException::class);

        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'transfer',
            'account_id' => $account->id,
            'counter_account_id' => $account->id,
            'amount' => 1000,
            'currency' => 'TRY',
        ]));
    }

    #[Test]
    public function a_transfer_never_carries_a_category(): void
    {
        // Moving your own money between your own accounts is not spending;
        // counting it would inflate every "where did my money go" report.
        $user = $this->makeUser('cat@example.test');
        $workspace = $this->makeWorkspace($user);
        $from = $this->makeAccount($workspace, 'A');
        $to = $this->makeAccount($workspace, 'B');
        $category = $this->makeCategory($workspace, 'Food');

        $transaction = $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'transfer',
            'account_id' => $from->id,
            'counter_account_id' => $to->id,
            'category_id' => $category->id,
            'amount' => 1000,
            'currency' => 'TRY',
        ]));

        $this->assertNull($transaction->category_id);
    }

    #[Test]
    public function replaying_the_same_idempotency_key_does_not_create_a_second_transaction(): void
    {
        $user = $this->makeUser('retry@example.test');
        $workspace = $this->makeWorkspace($user);
        $account = $this->makeAccount($workspace, openingBalance: 100000);

        $payload = [
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 25000,
            'currency' => 'TRY',
            'idempotency_key' => 'retry-after-timeout',
        ];

        [$first, $second] = $this->inWorkspace($workspace, function () use ($payload): array {
            $record = app(RecordTransaction::class);

            return [$record->handle($payload), $record->handle($payload)];
        });

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
        $this->assertSame(75000, $account->fresh()->current_balance);
    }
}
