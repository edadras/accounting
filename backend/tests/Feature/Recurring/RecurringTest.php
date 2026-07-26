<?php

declare(strict_types=1);

namespace Tests\Feature\Recurring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Actions\PostDueRecurring;
use Modules\Recurring\Models\RecurringRule;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersUnwiredProviders;
use Tests\Feature\LedgerTestCase;

/**
 * The recurring rules of docs/03-data-model.md, and the one thing a standing
 * instruction must never do: pay the rent twice because the nightly job ran
 * twice.
 */
final class RecurringTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersUnwiredProviders;

    #[Test]
    public function a_monthly_rule_posts_once_for_the_period(): void
    {
        [$workspace, $account] = $this->world('monthly@example.test');

        $rule = $this->rule($workspace, $account, ['starts_at' => '2026-07-05 09:00:00']);

        $posted = $this->postDue($workspace, '2026-07-10 12:00:00');

        $this->assertCount(1, $posted);
        $this->assertSame(100_00, $posted[0]->amount);
        $this->assertSame('recurring', $posted[0]->source);
        $meta = $posted[0]->source_meta;

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('recurring_rule_id', $meta);
        $this->assertSame($rule->id, $meta['recurring_rule_id']);
        $this->assertSame('2026-07-05', $posted[0]->occurred_at->toDateString());

        $fresh = $this->inWorkspace($workspace, fn () => $rule->fresh());
        $this->assertNotNull($fresh);

        $nextRun = $fresh->next_run_at;
        $this->assertNotNull($nextRun);
        $this->assertSame('2026-08-05', $nextRun->toDateString());
        $this->assertSame(9_900_00, $account->refresh()->current_balance);
    }

    #[Test]
    public function running_the_poster_twice_on_the_same_day_posts_nothing_extra(): void
    {
        [$workspace, $account] = $this->world('twice@example.test');

        $this->rule($workspace, $account, ['starts_at' => '2026-07-05 09:00:00']);

        $this->assertCount(1, $this->postDue($workspace, '2026-07-10 12:00:00'));
        $this->assertCount(0, $this->postDue($workspace, '2026-07-10 12:00:00'));
        $this->assertCount(0, $this->postDue($workspace, '2026-07-10 23:59:00'));

        $this->assertSame(1, $this->transactionCount($workspace));
        $this->assertSame(9_900_00, $account->refresh()->current_balance);
    }

    #[Test]
    public function a_rewound_rule_still_cannot_post_the_same_occurrence_twice(): void
    {
        [$workspace, $account] = $this->world('rewound@example.test');

        $rule = $this->rule($workspace, $account, ['starts_at' => '2026-07-05 09:00:00']);

        $this->postDue($workspace, '2026-07-10 12:00:00');

        // A rule whose advance was lost — a crash between the posting and the
        // save, a hand-edited row — must not be able to pay the same month again.
        $this->inWorkspace($workspace, fn () => $rule->refresh()->forceFill([
            'next_run_at' => '2026-07-05 09:00:00',
        ])->save());

        $posted = $this->postDue($workspace, '2026-07-10 12:00:00');

        $this->assertCount(1, $posted, 'The ledger returns the transaction it already has.');
        $this->assertSame(1, $this->transactionCount($workspace));
        $this->assertSame(9_900_00, $account->refresh()->current_balance);
    }

    #[Test]
    public function a_rule_left_unattended_catches_up_one_posting_per_period(): void
    {
        [$workspace, $account] = $this->world('catchup@example.test');

        $this->rule($workspace, $account, ['starts_at' => '2026-05-05 09:00:00']);

        $posted = $this->postDue($workspace, '2026-07-10 12:00:00');

        $this->assertSame(
            ['2026-05-05', '2026-06-05', '2026-07-05'],
            array_map(fn (Transaction $t): string => $t->occurred_at->toDateString(), $posted),
        );

        $this->assertSame(9_700_00, $account->refresh()->current_balance);
    }

    #[Test]
    public function an_ended_rule_stops(): void
    {
        [$workspace, $account] = $this->world('ended@example.test');

        $rule = $this->rule($workspace, $account, [
            'starts_at' => '2026-07-05 09:00:00',
            'ends_at' => '2026-08-31 23:59:59',
        ]);

        $posted = $this->postDue($workspace, '2026-12-01 12:00:00');

        $this->assertSame(
            ['2026-07-05', '2026-08-05'],
            array_map(fn (Transaction $t): string => $t->occurred_at->toDateString(), $posted),
        );

        $fresh = $this->inWorkspace($workspace, fn () => $rule->fresh());
        $this->assertNotNull($fresh);

        $this->assertNull($fresh->next_run_at, 'An exhausted rule must never come up as due again.');
        $this->assertCount(0, $this->postDue($workspace, '2027-01-01 12:00:00'));
        $this->assertSame(9_800_00, $account->refresh()->current_balance);
    }

    #[Test]
    public function a_paused_rule_and_a_reminder_only_rule_post_nothing(): void
    {
        [$workspace, $account] = $this->world('paused@example.test');

        $this->rule($workspace, $account, ['starts_at' => '2026-07-05 09:00:00', 'is_paused' => true]);
        $this->rule($workspace, $account, ['starts_at' => '2026-07-05 09:00:00', 'auto_post' => false]);

        $this->assertCount(0, $this->postDue($workspace, '2026-07-10 12:00:00'));
        $this->assertSame(10_000_00, $account->refresh()->current_balance);
    }

    #[Test]
    public function a_monthly_rule_on_the_thirty_first_lands_on_the_last_day_of_a_short_month(): void
    {
        [$workspace, $account] = $this->world('shortmonth@example.test');

        $this->rule($workspace, $account, [
            'starts_at' => '2026-01-31 09:00:00',
            'day_of_month' => 31,
        ]);

        $posted = $this->postDue($workspace, '2026-04-05 12:00:00');

        $this->assertSame(
            ['2026-01-31', '2026-02-28', '2026-03-31'],
            array_map(fn (Transaction $t): string => $t->occurred_at->toDateString(), $posted),
            'A month too short for the 31st must not be skipped, and must not spill into the next one.',
        );
    }

    #[Test]
    public function another_workspaces_rules_are_never_posted(): void
    {
        [$theirWorkspace, $theirAccount] = $this->world('their-rules@example.test');
        $this->rule($theirWorkspace, $theirAccount, ['starts_at' => '2026-07-05 09:00:00']);

        [$myWorkspace, $myAccount] = $this->world('my-rules@example.test');

        $this->assertCount(0, $this->postDue($myWorkspace, '2026-07-10 12:00:00'));
        $this->assertSame(10_000_00, $theirAccount->refresh()->current_balance);
        $this->assertSame(10_000_00, $myAccount->refresh()->current_balance);
    }

    #[Test]
    public function the_console_command_posts_every_workspaces_due_rules(): void
    {
        [$firstWorkspace, $firstAccount] = $this->world('cmd-one@example.test');
        [$secondWorkspace, $secondAccount] = $this->world('cmd-two@example.test');

        $this->rule($firstWorkspace, $firstAccount, ['starts_at' => '2026-07-05 09:00:00']);
        $this->rule($secondWorkspace, $secondAccount, ['starts_at' => '2026-07-05 09:00:00']);

        $this->command('recurring:post --at="2026-07-10 12:00:00"')->assertSuccessful();

        $this->assertSame(9_900_00, $firstAccount->refresh()->current_balance);
        $this->assertSame(9_900_00, $secondAccount->refresh()->current_balance);

        // Running the nightly job again the same day changes nothing.
        $this->command('recurring:post --at="2026-07-10 12:00:00"')->assertSuccessful();

        $this->assertSame(9_900_00, $firstAccount->refresh()->current_balance);
        $this->assertSame(9_900_00, $secondAccount->refresh()->current_balance);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * artisan() hands back a bare exit code once console output is no longer
     * mocked, and only the PendingCommand carries the assertions.
     */
    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);

        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    #[Test]
    public function a_weekly_rule_lands_on_the_requested_weekday(): void
    {
        [$workspace, $account] = $this->world('weekday@example.test');

        // Set up on a Monday, asking for Tuesday. `day_of_week` was validated
        // and stored and then never read, so the rule simply repeated the day
        // it started on — "every other Tuesday" quietly ran every Monday.
        $rule = $this->rule($workspace, $account, [
            'frequency' => RecurringRule::WEEKLY,
            'interval' => 2,
            'day_of_week' => 2,
            'starts_at' => '2026-07-06 09:00:00',
        ]);

        $next = $rule->occurrenceAfter(new \DateTimeImmutable('2026-07-06 09:00:00'));

        $this->assertNotNull($next);
        $this->assertSame('2026-07-21', $next->toDateString());
        $this->assertSame(2, $next->dayOfWeek);
    }

    #[Test]
    public function a_weekly_rule_without_a_weekday_keeps_its_own_day(): void
    {
        [$workspace, $account] = $this->world('weekday-null@example.test');

        $rule = $this->rule($workspace, $account, [
            'frequency' => RecurringRule::WEEKLY,
            'interval' => 1,
            'day_of_week' => null,
            'starts_at' => '2026-07-06 09:00:00',
        ]);

        $next = $rule->occurrenceAfter(new \DateTimeImmutable('2026-07-06 09:00:00'));

        $this->assertNotNull($next);
        $this->assertSame('2026-07-13', $next->toDateString());
    }

    #[Test]
    public function the_schedule_and_the_template_can_be_edited(): void
    {
        [$workspace, $account] = $this->world('recurring-patch@example.test');
        $rule = $this->rule($workspace, $account);

        $owner = $workspace->owner;
        $this->assertNotNull($owner);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/recurring-rules/{$rule->id}", [
            'frequency' => RecurringRule::WEEKLY,
            'interval' => 2,
            'day_of_week' => 4,
            'template' => [
                'type' => Transaction::TYPE_EXPENSE,
                'account_id' => $account->id,
                'amount' => 250_00,
                'currency' => 'TRY',
                'description' => 'Rent, raised',
            ],
        ], ['X-Workspace-Id' => $workspace->id])
            ->assertOk()
            ->assertJsonPath('data.frequency', RecurringRule::WEEKLY)
            ->assertJsonPath('data.interval', 2);

        // Same rule, not a replacement: changing an amount used to mean
        // deleting the rule and rebuilding it, losing its id and its history.
        $this->assertSame(1, RecurringRule::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)->count());

        $fresh = $this->inWorkspace($workspace, fn () => RecurringRule::query()->findOrFail($rule->id));
        $this->assertSame(250_00, $fresh->template['amount']);
    }

    /** @return array{0: Workspace, 1: Account} */
    private function world(string $email): array
    {
        $workspace = $this->makeWorkspace($this->makeUser($email));

        return [$workspace, $this->makeAccount($workspace, 'Bank', 'TRY', 10_000_00)];
    }

    /** @param  array<string, mixed>  $attributes */
    private function rule(Workspace $workspace, Account $account, array $attributes = []): RecurringRule
    {
        return $this->inWorkspace($workspace, fn () => RecurringRule::query()->create(array_merge([
            'name' => 'Rent',
            'template' => [
                'type' => Transaction::TYPE_EXPENSE,
                'account_id' => $account->id,
                'amount' => 100_00,
                'currency' => 'TRY',
                'description' => 'Rent',
            ],
            'frequency' => RecurringRule::MONTHLY,
            'interval' => 1,
            'starts_at' => '2026-07-05 09:00:00',
        ], $attributes)));
    }

    /** @return list<Transaction> */
    private function postDue(Workspace $workspace, string $at): array
    {
        return $this->inWorkspace(
            $workspace,
            fn () => app(PostDueRecurring::class)->handle(new \DateTimeImmutable($at)),
        );
    }

    private function transactionCount(Workspace $workspace): int
    {
        return $this->inWorkspace($workspace, fn () => Transaction::query()->count());
    }
}
