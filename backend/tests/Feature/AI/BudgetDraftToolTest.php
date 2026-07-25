<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Illuminate\Support\Facades\DB;
use Modules\AI\Actions\AnswerQuestion;
use Modules\AI\Tools\ToolRegistry;
use Modules\Core\Models\Workspace;
use PHPUnit\Framework\Attributes\Test;

/**
 * «بودجه ماه آینده را تنظیم کن» — docs/08-ai-layer.md §5, under the rule the
 * document opens with: AI proposes, the user decides.
 *
 * So there are two things to prove, and the second matters more than the
 * first: that the numbers are drawn from what was actually spent, and that
 * asking for them writes nothing. Every test here asserts the budgets table is
 * still empty afterwards, because a tool called `create_budget_draft` that one
 * day starts creating budgets would be a bug nobody notices until a user's
 * ledger has a ceiling on it that they never agreed to.
 */
final class BudgetDraftToolTest extends AiTestCase
{
    #[Test]
    public function it_proposes_the_average_of_what_was_actually_spent(): void
    {
        [, $workspace] = $this->world('draft@example.test');

        // Now is 2026-07-25, so the three complete months are April, May and
        // June. ₺100 + ₺200 + ₺300 on food averages ₺200 a month.
        $food = $this->categoryNamed($workspace, 'restaurant');
        $this->spend($workspace, $this->wallet($workspace), $food, 100_00, '2026-04-10 10:00:00');
        $this->spend($workspace, $this->wallet($workspace), $food, 200_00, '2026-05-10 10:00:00');
        $this->spend($workspace, $this->wallet($workspace), $food, 300_00, '2026-06-10 10:00:00');

        $draft = $this->draft($workspace);

        $this->assertSame('2026-08', $draft['period']);
        $this->assertSame('2026-04-01', $draft['based_on']['from']);
        $this->assertSame('2026-06-30', $draft['based_on']['to']);
        $this->assertSame(3, $draft['based_on']['months']);
        $this->assertSame(3, $draft['based_on']['transaction_count']);

        $this->assertSame(600_00, $draft['overall']['total_spent']);
        $this->assertSame(200_00, $draft['overall']['suggested_amount']);
        $this->assertSame('TRY', $draft['currency']);

        $this->assertCount(1, $draft['lines']);
        $this->assertSame(200_00, $draft['lines'][0]['suggested_amount']);
        $this->assertSame(600_00, $draft['lines'][0]['total_spent']);

        $this->assertNoBudgetWasWritten();
    }

    #[Test]
    public function it_writes_no_budget_row(): void
    {
        [, $workspace] = $this->world('nowrite@example.test');

        $this->spend($workspace, $this->wallet($workspace), null, 500_00, '2026-05-10 10:00:00');

        $before = DB::table('budgets')->count();

        $draft = $this->draft($workspace);

        $this->assertTrue($draft['draft']);
        $this->assertFalse($draft['persisted']);
        $this->assertTrue($draft['needs_confirmation']);

        $this->assertSame($before, DB::table('budgets')->count());
        $this->assertSame(0, DB::table('budgets')->count());
        $this->assertSame(0, DB::table('budget_usages')->count());
    }

    #[Test]
    public function the_class_has_no_expression_that_could_reach_a_budget(): void
    {
        // The structural half of the promise above. A test on behaviour can
        // only show that today's code path does not write; this shows there is
        // no path at all, which is what makes the guarantee survive editing.
        // Prose is stripped first: the class is allowed to explain itself, it
        // is only forbidden to reach.
        $code = $this->codeOf(dirname(__DIR__, 3).'/modules/AI/Tools/CreateBudgetDraft.php');

        // Nothing from the module that owns budgets is even in scope.
        $this->assertStringNotContainsString('Modules\Budget', $code);
        $this->assertStringNotContainsString('Budget::', $code);

        // And nothing anywhere in the class writes to anything.
        foreach (['->save(', '::create(', 'firstOrNew(', 'updateOrCreate(', '->update(', '->insert(', '->delete(', 'DB::'] as $write) {
            $this->assertStringNotContainsString($write, $code, "create_budget_draft must contain no [{$write}].");
        }
    }

    #[Test]
    public function lines_are_grouped_under_the_root_category_and_ordered_by_size(): void
    {
        [, $workspace] = $this->world('grouped@example.test');

        // restaurant, groceries and rent all hang under `home` in the seeded
        // tree, so one "home" line is proposed rather than three the user has
        // to merge; fuel sits under `transport` and stays its own line.
        $this->spend($workspace, $this->wallet($workspace), $this->categoryNamed($workspace, 'restaurant'), 300_00, '2026-05-02 10:00:00');
        $this->spend($workspace, $this->wallet($workspace), $this->categoryNamed($workspace, 'groceries'), 300_00, '2026-05-03 10:00:00');
        $this->spend($workspace, $this->wallet($workspace), $this->categoryNamed($workspace, 'fuel'), 900_00, '2026-05-04 10:00:00');

        $draft = $this->draft($workspace);

        $this->assertSame(['transport', 'home'], array_column($draft['lines'], 'name'));
        $this->assertSame(900_00, $draft['lines'][0]['total_spent']);
        $this->assertSame(600_00, $draft['lines'][1]['total_spent']);
        $this->assertGreaterThan($draft['lines'][1]['average_monthly'], $draft['lines'][0]['average_monthly']);

        $this->assertNoBudgetWasWritten();
    }

    #[Test]
    public function a_trivial_category_is_left_out_of_the_draft(): void
    {
        [, $workspace] = $this->world('noise@example.test');

        $this->spend($workspace, $this->wallet($workspace), $this->categoryNamed($workspace, 'rent'), 1_000_00, '2026-05-02 10:00:00');
        $this->spend($workspace, $this->wallet($workspace), $this->categoryNamed($workspace, 'fuel'), 1_00, '2026-05-03 10:00:00');

        $draft = $this->draft($workspace);

        // The ₺1 line is 0.1% of the month and would be pure admin; the
        // overall figure still covers it.
        $this->assertCount(1, $draft['lines']);
        $this->assertSame(1_001_00, $draft['overall']['total_spent']);
    }

    #[Test]
    public function the_months_argument_widens_the_history_it_averages(): void
    {
        [, $workspace] = $this->world('lookback@example.test');

        $this->spend($workspace, $this->wallet($workspace), null, 600_00, '2026-06-10 10:00:00');

        // One month back: ₺600 over one month.
        $this->assertSame(600_00, $this->draft($workspace, ['months' => 1])['overall']['suggested_amount']);

        // Six months back: the same ₺600 spread over six.
        $this->assertSame(100_00, $this->draft($workspace, ['months' => 6])['overall']['suggested_amount']);

        $this->assertNoBudgetWasWritten();
    }

    #[Test]
    public function a_workspace_with_no_history_proposes_nothing_rather_than_guessing(): void
    {
        [, $workspace] = $this->world('blank@example.test');

        $draft = $this->draft($workspace);

        $this->assertSame([], $draft['lines']);
        $this->assertSame(0, $draft['overall']['suggested_amount']);
        $this->assertSame(0, $draft['based_on']['transaction_count']);
    }

    #[Test]
    public function it_never_proposes_from_another_workspaces_spending(): void
    {
        [$mine, $theirs] = $this->twoLedgers();

        $draft = $this->draft($mine);

        $this->assertSame(20_00, $draft['overall']['total_spent']);

        $encoded = (string) json_encode($draft, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($theirs->id, $encoded);
        $this->assertStringNotContainsString('9900', $encoded);
    }

    #[Test]
    public function a_workspace_argument_is_dropped_before_the_tool_runs(): void
    {
        [$mine, $theirs] = $this->twoLedgers();

        $outcome = $this->inWorkspace($mine, fn () => app(ToolRegistry::class)->execute('create_budget_draft', [
            'workspace_id' => $theirs->id,
            'workspace' => $theirs->id,
            'months' => 3,
        ]));

        $this->assertEqualsCanonicalizing(['workspace_id', 'workspace'], $outcome['dropped_arguments']);
        $this->assertSame(['months' => 3], $outcome['arguments']);
        $this->assertSame(20_00, $outcome['result']['overall']['total_spent']);

        $this->assertNoBudgetWasWritten();
    }

    #[Test]
    public function the_chat_reaches_the_tool_with_no_network(): void
    {
        [, $workspace] = $this->world('chat-draft@example.test');

        $this->spend($workspace, $this->wallet($workspace), $this->categoryNamed($workspace, 'restaurant'), 300_00, '2026-05-10 10:00:00');

        $answer = $this->inWorkspace(
            $workspace,
            fn () => app(AnswerQuestion::class)->handle('بودجه ماه آینده را تنظیم کن'),
        );

        $this->assertSame(['create_budget_draft'], $answer['tool_calls']);
        $this->assertSame('deterministic', $answer['provider']);
        $this->assertStringContainsString('2026-08', $answer['answer']);

        // The answer says out loud that nothing has been saved.
        $this->assertStringContainsString('تأیید', $answer['answer']);

        $this->assertNoBudgetWasWritten();
    }

    #[Test]
    public function asking_about_budgets_rather_than_for_one_still_reports_status(): void
    {
        [, $workspace] = $this->world('status@example.test');

        $answer = $this->inWorkspace(
            $workspace,
            fn () => app(AnswerQuestion::class)->handle('وضعیت بودجه من چطور است؟'),
        );

        $this->assertSame(['get_budget_status'], $answer['tool_calls']);
    }

    #[Test]
    public function the_english_phrasing_reaches_the_draft_too(): void
    {
        [, $workspace] = $this->world('chat-draft-en@example.test');

        $answer = $this->inWorkspace(
            $workspace,
            fn () => app(AnswerQuestion::class)->handle('draft a budget for next month'),
        );

        $this->assertSame(['create_budget_draft'], $answer['tool_calls']);

        $this->assertNoBudgetWasWritten();
    }

    /** @return array{Workspace, Workspace} */
    private function twoLedgers(): array
    {
        [, $mine] = $this->world('alpha-budget@example.test');
        [, $theirs] = $this->world('beta-budget@example.test');

        $this->spend($mine, $this->wallet($mine), null, 20_00, '2026-05-10 10:00:00');
        $this->spend($theirs, $this->wallet($theirs), null, 99_00, '2026-05-10 10:00:00');

        return [$mine, $theirs];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function draft(Workspace $workspace, array $arguments = []): array
    {
        return $this->inWorkspace(
            $workspace,
            fn () => app(ToolRegistry::class)->execute('create_budget_draft', $arguments)['result'],
        );
    }

    /** The file with every comment removed. */
    private function codeOf(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function assertNoBudgetWasWritten(): void
    {
        $this->assertSame(0, DB::table('budgets')->count(), 'create_budget_draft must never write a budget.');
    }
}
