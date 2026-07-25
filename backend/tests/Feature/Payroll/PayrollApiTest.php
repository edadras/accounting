<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Account;
use Modules\Payroll\Models\PayrollRun;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every payroll endpoint over the real HTTP stack, with the ways it can be
 * asked wrongly.
 *
 * An endpoint that is only ever tested on its happy path is an endpoint whose
 * refusals are guesses, and a payroll refusal is the difference between paying
 * somebody twice and not.
 */
final class PayrollApiTest extends PayrollTestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- employees

    #[Test]
    public function the_api_hires_lists_reads_and_amends_an_employee(): void
    {
        [$headers] = $this->signedIn('hire-api@example.test');

        $created = $this->postJson('/api/v1/employees', [
            'name' => 'Ada Yılmaz',
            'job_title' => 'Engineer',
            'country' => 'XX',
            'started_on' => '2026-01-01',
            'national_id' => '12345678901',
            'compensation' => [
                'amount' => 1234567,
                'currency' => 'TRY',
                'period' => 'monthly',
            ],
        ], $headers)->assertCreated();

        // Money always leaves the API as {value, currency, minor_unit, decimal}.
        $created
            ->assertJsonPath('data.name', 'Ada Yılmaz')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.compensation.amount.value', 1234567)
            ->assertJsonPath('data.compensation.amount.currency', 'TRY')
            ->assertJsonPath('data.compensation.amount.decimal', '12345.67')
            ->assertJsonPath('data.has_national_id', true);

        // The identifier itself is encrypted at rest and never leaves.
        $this->assertStringNotContainsString('12345678901', $created->getContent() ?: '');

        $id = $created->json('data.id');

        $this->getJson('/api/v1/employees', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/v1/employees/{$id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.job_title', 'Engineer');

        $this->patchJson("/api/v1/employees/{$id}", [
            'job_title' => 'Staff Engineer',
            'ended_on' => '2026-06-30',
        ], $headers)->assertOk()
            ->assertJsonPath('data.job_title', 'Staff Engineer')
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.has_ended', true);

        $this->postJson("/api/v1/employees/{$id}/compensation", [
            'amount' => 1_500_000,
            'currency' => 'TRY',
            'effective_from' => '2026-04-01',
        ], $headers)->assertCreated()
            ->assertJsonPath('data.compensation.amount.value', 1_500_000);
    }

    #[Test]
    public function hiring_somebody_without_a_name_or_with_a_bad_amount_is_refused(): void
    {
        [$headers] = $this->signedIn('hire-invalid@example.test');

        $this->postJson('/api/v1/employees', [
            'started_on' => '2026-01-01',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('name');

        // A decimal amount is not an amount: minor units are integers.
        $this->postJson('/api/v1/employees', [
            'name' => 'Ada',
            'started_on' => '2026-01-01',
            'compensation' => ['amount' => 12.34, 'currency' => 'TRY'],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('compensation.amount');

        $this->postJson('/api/v1/employees', [
            'name' => 'Ada',
            'started_on' => '2026-01-01',
            'compensation' => ['amount' => 1000, 'currency' => 'XYZ'],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('compensation.currency');

        $this->postJson('/api/v1/employees', [
            'name' => 'Ada',
            'started_on' => '2026-06-01',
            'ended_on' => '2026-01-01',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('ended_on');
    }

    #[Test]
    public function reading_or_amending_an_employee_that_does_not_exist_is_a_404(): void
    {
        [$headers] = $this->signedIn('missing-employee@example.test');
        $ghost = (string) Str::ulid();

        $this->getJson("/api/v1/employees/{$ghost}", $headers)->assertNotFound();
        $this->patchJson("/api/v1/employees/{$ghost}", ['name' => 'Nobody'], $headers)->assertNotFound();
        $this->postJson("/api/v1/employees/{$ghost}/compensation", [
            'amount' => 1000,
            'currency' => 'TRY',
        ], $headers)->assertNotFound();
    }

    #[Test]
    public function amending_an_employee_with_an_unknown_status_is_refused(): void
    {
        [$headers, , $employeeId] = $this->workspaceWithOneEmployee('bad-status@example.test');

        $this->patchJson("/api/v1/employees/{$employeeId}", [
            'status' => 'retired',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // ------------------------------------------------------------- tax rules

    #[Test]
    public function the_api_stores_and_lists_a_country_rule_set(): void
    {
        [$headers] = $this->signedIn('rules-api@example.test');

        $this->getJson('/api/v1/payroll/tax-rules', $headers)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/payroll/tax-rules', [
            'country' => 'tr',
            'name' => 'Türkiye 2026',
            'effective_from' => '2026-01-01',
            'rules' => [
                'tax_base' => 'gross',
                'brackets' => [
                    ['up_to' => 500_000, 'rate' => 15],
                    ['up_to' => null, 'rate' => 27],
                ],
                'employee_contributions' => [
                    ['code' => 'sgk', 'label' => 'SGK', 'rate' => 14],
                ],
            ],
        ], $headers)->assertCreated()
            ->assertJsonPath('data.country', 'TR')
            ->assertJsonPath('data.name', 'Türkiye 2026')
            ->assertJsonPath('data.rules.tax_base', 'gross');

        $this->getJson('/api/v1/payroll/tax-rules?country=TR', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/payroll/tax-rules?country=DE', $headers)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_rule_set_without_a_country_or_with_an_impossible_rate_is_refused(): void
    {
        [$headers] = $this->signedIn('rules-invalid@example.test');

        $this->postJson('/api/v1/payroll/tax-rules', [
            'rules' => ['brackets' => []],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('country');

        $this->postJson('/api/v1/payroll/tax-rules', [
            'country' => 'DE',
            'rules' => ['brackets' => [['up_to' => null, 'rate' => -5]]],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('rules.brackets.0.rate');

        // Descending ceilings are not a validation rule but a domain refusal,
        // and answer with the module's own code.
        $this->postJson('/api/v1/payroll/tax-rules', [
            'country' => 'DE',
            'effective_from' => '2026-01-01',
            'rules' => [
                'brackets' => [
                    ['up_to' => 900_000, 'rate' => 10],
                    ['up_to' => 500_000, 'rate' => 20],
                ],
            ],
        ], $headers)->assertStatus(422)->assertJsonPath('error.code', 'tax_brackets_out_of_order');
    }

    // ------------------------------------------------------------------ runs

    #[Test]
    public function the_api_walks_a_run_from_draft_through_approved_to_paid(): void
    {
        [$headers, $account, $employeeId] = $this->workspaceWithOneEmployee('run-api@example.test');

        $this->getJson('/api/v1/payroll/runs', $headers)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $created = $this->postJson('/api/v1/payroll/runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'account_id' => $account->id,
            'currency' => 'TRY',
        ], $headers)->assertCreated();

        $created
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.reference', 'PAY-2026-03')
            ->assertJsonPath('data.is_posted', false)
            ->assertJsonPath('data.gross.value', 1234567)
            ->assertJsonPath('data.net.value', 1_013_079)
            ->assertJsonPath('data.employer_cost.value', 1_388_888)
            ->assertJsonCount(1, 'data.payslips');

        $runId = $created->json('data.id');
        $payslipId = $created->json('data.payslips.0.id');

        $this->assertSame($employeeId, $created->json('data.payslips.0.employee_id'));

        // A draft has cost nothing yet.
        $this->assertSame(5_000_000, $account->refresh()->current_balance);

        $this->getJson("/api/v1/payroll/runs/{$runId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->getJson("/api/v1/payroll/payslips/{$payslipId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.gross.value', 1234567)
            ->assertJsonPath('data.net.value', 1_013_079)
            ->assertJsonPath('data.lines.0.code', 'base_pay');

        $this->postJson("/api/v1/payroll/runs/{$runId}/approve", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_posted', true);

        $this->assertSame(5_000_000 - 1_388_888, $account->refresh()->current_balance);

        // The retry a dropped connection produces.
        $this->postJson("/api/v1/payroll/runs/{$runId}/approve", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(
            5_000_000 - 1_388_888,
            $account->refresh()->current_balance,
            'Approving twice must not have posted twice.',
        );

        $this->postJson("/api/v1/payroll/runs/{$runId}/pay", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->getJson('/api/v1/payroll/runs?status=paid', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_run_asked_for_with_a_bad_period_or_a_missing_account_is_refused(): void
    {
        [$headers, $account] = $this->workspaceWithOneEmployee('run-invalid@example.test');

        $this->postJson('/api/v1/payroll/runs', [
            'period_start' => '2026-03-31',
            'period_end' => '2026-03-01',
            'account_id' => $account->id,
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('period_end');

        $this->postJson('/api/v1/payroll/runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('account_id');

        $this->postJson('/api/v1/payroll/runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'account_id' => (string) Str::ulid(),
        ], $headers)->assertNotFound()->assertJsonPath('error.code', 'payroll_account_not_found');
    }

    #[Test]
    public function a_run_that_does_not_exist_cannot_be_read_approved_paid_or_discarded(): void
    {
        [$headers] = $this->workspaceWithOneEmployee('run-missing@example.test');
        $ghost = (string) Str::ulid();

        $this->getJson("/api/v1/payroll/runs/{$ghost}", $headers)->assertNotFound();
        $this->postJson("/api/v1/payroll/runs/{$ghost}/approve", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/payroll/runs/{$ghost}/pay", [], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/payroll/runs/{$ghost}", [], $headers)->assertNotFound();
        $this->getJson("/api/v1/payroll/payslips/{$ghost}", $headers)->assertNotFound();
    }

    #[Test]
    public function the_api_refuses_to_pay_a_run_that_was_never_approved_and_to_discard_one_that_was(): void
    {
        [$headers, $account] = $this->workspaceWithOneEmployee('run-states@example.test');

        $runId = $this->postJson('/api/v1/payroll/runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'account_id' => $account->id,
        ], $headers)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/payroll/runs/{$runId}/pay", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payroll_run_not_approved');

        $this->postJson("/api/v1/payroll/runs/{$runId}/approve", [], $headers)->assertOk();

        $this->deleteJson("/api/v1/payroll/runs/{$runId}", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payroll_run_not_a_draft');
    }

    #[Test]
    public function the_api_discards_a_draft(): void
    {
        [$headers, $account] = $this->workspaceWithOneEmployee('run-discard@example.test');

        $runId = $this->postJson('/api/v1/payroll/runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'account_id' => $account->id,
        ], $headers)->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/payroll/runs/{$runId}", [], $headers)->assertNoContent();

        $this->getJson("/api/v1/payroll/runs/{$runId}", $headers)->assertNotFound();
        $this->getJson('/api/v1/payroll/runs', $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function the_api_refuses_every_change_to_a_run_that_has_been_paid(): void
    {
        [$headers, $account] = $this->workspaceWithOneEmployee('run-immutable@example.test');

        $runId = $this->postJson('/api/v1/payroll/runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'account_id' => $account->id,
        ], $headers)->assertCreated()->json('data.id');

        $this->postJson("/api/v1/payroll/runs/{$runId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$runId}/pay", [], $headers)->assertOk();

        $this->postJson("/api/v1/payroll/runs/{$runId}/approve", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payroll_run_is_paid');

        $this->deleteJson("/api/v1/payroll/runs/{$runId}", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payroll_run_is_paid');

        // Paying an already paid run is the retry case and stays a no-op.
        $this->postJson("/api/v1/payroll/runs/{$runId}/pay", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
    }

    // ------------------------------------------------------------------ roles

    #[Test]
    public function a_viewer_may_read_payroll_but_not_run_it(): void
    {
        $owner = $this->makeUser('payroll-owner@example.test');
        $workspace = $this->makeWorkspace($owner, 'Expandia');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        $this->inWorkspace($workspace, fn () => $this->hire('Ada Yılmaz'));

        $viewer = $this->makeUser('payroll-viewer@example.test');
        $workspace->members()->create(['user_id' => $viewer->id, 'role' => 'viewer', 'joined_at' => now()]);

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($viewer);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $this->getJson('/api/v1/employees', $headers)->assertOk();
        $this->getJson('/api/v1/payroll/runs', $headers)->assertOk();
        $this->getJson('/api/v1/payroll/tax-rules', $headers)->assertOk();

        $this->postJson('/api/v1/employees', [
            'name' => 'Nope',
            'started_on' => '2026-01-01',
        ], $headers)->assertForbidden();

        $this->postJson('/api/v1/payroll/tax-rules', [
            'country' => 'DE',
            'rules' => ['brackets' => []],
        ], $headers)->assertForbidden();

        $this->postJson('/api/v1/payroll/runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'account_id' => $account->id,
        ], $headers)->assertForbidden();

        // A viewer must not be able to approve, pay or discard either, and that
        // is checked in the controller rather than in a form request.
        $runId = $this->inWorkspace($workspace, function () use ($account) {
            return app(\Modules\Payroll\Actions\CreatePayrollRun::class)->handle([
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-31',
                'account_id' => $account->id,
                'currency' => 'TRY',
            ])->id;
        });

        app(WorkspaceContext::class)->forget();

        $this->postJson("/api/v1/payroll/runs/{$runId}/approve", [], $headers)->assertForbidden();
        $this->postJson("/api/v1/payroll/runs/{$runId}/pay", [], $headers)->assertForbidden();
        $this->deleteJson("/api/v1/payroll/runs/{$runId}", [], $headers)->assertForbidden();

        $this->assertSame(PayrollRun::STATUS_DRAFT, PayrollRun::withoutGlobalScopes()->findOrFail($runId)->status);
    }

    #[Test]
    public function every_payroll_endpoint_needs_a_workspace_header(): void
    {
        $user = $this->makeUser('no-header@example.test');
        $this->makeWorkspace($user, 'Expandia');

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/employees')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'workspace_required');

        $this->getJson('/api/v1/payroll/runs')->assertStatus(400);
        $this->getJson('/api/v1/payroll/tax-rules')->assertStatus(400);
    }

    #[Test]
    public function payroll_endpoints_are_closed_to_anybody_who_is_not_signed_in(): void
    {
        $this->getJson('/api/v1/employees')->assertUnauthorized();
        $this->getJson('/api/v1/payroll/runs')->assertUnauthorized();
        $this->getJson('/api/v1/payroll/tax-rules')->assertUnauthorized();
    }

    // ----------------------------------------------------------------- setup

    /**
     * @return array{0: array<string, string>, 1: Workspace}
     */
    private function signedIn(string $email): array
    {
        $owner = $this->makeUser($email);
        $workspace = $this->makeWorkspace($owner, 'Expandia');

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($owner);

        return [['X-Workspace-Id' => $workspace->id], $workspace];
    }

    /**
     * @return array{0: array<string, string>, 1: Account, 2: string}
     */
    private function workspaceWithOneEmployee(string $email): array
    {
        $owner = $this->makeUser($email);
        $workspace = $this->makeWorkspace($owner, 'Expandia');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        $employee = $this->inWorkspace($workspace, fn () => $this->hire('Ada Yılmaz', amount: 1234567));

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($owner);

        return [['X-Workspace-Id' => $workspace->id], $account, $employee->id];
    }
}
