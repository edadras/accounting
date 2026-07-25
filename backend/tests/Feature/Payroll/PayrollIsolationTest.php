<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Support\WorkspaceContext;
use Modules\Payroll\Actions\ApprovePayrollRun;
use Modules\Payroll\Actions\CreatePayrollRun;
use Modules\Payroll\Actions\StoreTaxRuleSet;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use PHPUnit\Framework\Attributes\Test;

/**
 * Salaries are the most sensitive numbers in the product: knowing what a
 * colleague earns is not a report, it is a leak. docs/07-security.md requires,
 * for every endpoint, a test proving a user from another workspace gets 403 —
 * this is payroll's.
 *
 * Two attacks are covered separately, because they are stopped by different
 * things: a header the caller has no claim to (the middleware), and a header
 * they do have a claim to carrying somebody else's id (the global scope).
 */
final class PayrollIsolationTest extends PayrollTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_from_another_workspace_is_refused_by_every_payroll_endpoint(): void
    {
        [$intruder, $victimWorkspace] = $this->twoCompanies();

        Sanctum::actingAs($intruder);
        $headers = ['X-Workspace-Id' => $victimWorkspace->id];

        $ghost = (string) Str::ulid();

        $this->getJson('/api/v1/employees', $headers)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'workspace_forbidden');

        $this->postJson('/api/v1/employees', ['name' => 'Mole', 'started_on' => '2026-01-01'], $headers)
            ->assertForbidden();
        $this->getJson("/api/v1/employees/{$ghost}", $headers)->assertForbidden();
        $this->patchJson("/api/v1/employees/{$ghost}", ['name' => 'Mole'], $headers)->assertForbidden();
        $this->postJson("/api/v1/employees/{$ghost}/compensation", ['amount' => 1, 'currency' => 'TRY'], $headers)
            ->assertForbidden();

        $this->getJson('/api/v1/payroll/tax-rules', $headers)->assertForbidden();
        $this->postJson('/api/v1/payroll/tax-rules', ['country' => 'DE', 'rules' => []], $headers)->assertForbidden();

        $this->getJson('/api/v1/payroll/runs', $headers)->assertForbidden();
        $this->postJson('/api/v1/payroll/runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'account_id' => $ghost,
        ], $headers)->assertForbidden();
        $this->getJson("/api/v1/payroll/runs/{$ghost}", $headers)->assertForbidden();
        $this->postJson("/api/v1/payroll/runs/{$ghost}/approve", [], $headers)->assertForbidden();
        $this->postJson("/api/v1/payroll/runs/{$ghost}/pay", [], $headers)->assertForbidden();
        $this->deleteJson("/api/v1/payroll/runs/{$ghost}", [], $headers)->assertForbidden();
        $this->getJson("/api/v1/payroll/payslips/{$ghost}", $headers)->assertForbidden();
    }

    #[Test]
    public function a_header_you_are_entitled_to_does_not_unlock_another_companys_payroll(): void
    {
        [$intruder, , $intruderWorkspace, $ids] = $this->twoCompanies();

        Sanctum::actingAs($intruder);
        $headers = ['X-Workspace-Id' => $intruderWorkspace->id];

        // The subtler attack: a valid header plus somebody else's ids. The
        // global scope, not the middleware, has to stop this one.
        $this->getJson("/api/v1/employees/{$ids['employee']}", $headers)->assertNotFound();
        $this->patchJson("/api/v1/employees/{$ids['employee']}", ['name' => 'Renamed'], $headers)->assertNotFound();
        $this->postJson("/api/v1/employees/{$ids['employee']}/compensation", [
            'amount' => 1,
            'currency' => 'TRY',
        ], $headers)->assertNotFound();

        $this->getJson("/api/v1/payroll/runs/{$ids['run']}", $headers)->assertNotFound();
        $this->postJson("/api/v1/payroll/runs/{$ids['run']}/approve", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/payroll/runs/{$ids['run']}/pay", [], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/payroll/runs/{$ids['run']}", [], $headers)->assertNotFound();
        $this->getJson("/api/v1/payroll/payslips/{$ids['payslip']}", $headers)->assertNotFound();

        // And the lists show nothing at all rather than somebody else's staff.
        $this->getJson('/api/v1/employees', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/payroll/runs', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/payroll/tax-rules', $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function another_companys_payroll_is_invisible_to_the_model_layer_too(): void
    {
        [, , $intruderWorkspace] = $this->twoCompanies();

        $this->inWorkspace($intruderWorkspace, function (): void {
            $this->assertSame(0, Employee::query()->count());
            $this->assertSame(0, PayrollRun::query()->count());
            $this->assertSame(0, Payslip::query()->count());
        });
    }

    #[Test]
    public function with_no_workspace_active_the_payroll_tables_read_as_empty_rather_than_as_everything(): void
    {
        $this->twoCompanies();

        app(WorkspaceContext::class)->forget();

        // Failing closed: a wiring mistake produces an empty list, never
        // another company's salaries.
        $this->assertSame(0, Employee::query()->count());
        $this->assertSame(0, PayrollRun::query()->count());
        $this->assertSame(0, Payslip::query()->count());
    }

    /**
     * @return array{0: \App\Models\User, 1: \Modules\Core\Models\Workspace, 2: \Modules\Core\Models\Workspace, 3: array{employee:string,run:string,payslip:string}}
     */
    private function twoCompanies(): array
    {
        $victim = $this->makeUser('victim-payroll@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim Ltd');
        $account = $this->makeAccount($victimWorkspace, 'Bank', 'TRY', 9_000_000);

        $ids = $this->inWorkspace($victimWorkspace, function () use ($account): array {
            app(StoreTaxRuleSet::class)->handle([
                'country' => 'TR',
                'effective_from' => '2026-01-01',
                'rules' => ['brackets' => [['up_to' => null, 'rate' => '10']]],
            ]);

            $employee = $this->hire('Secret Salary', amount: 4_444_444);

            $run = app(ApprovePayrollRun::class)->handle(
                app(CreatePayrollRun::class)->handle($this->runPayload($account)),
            );

            return [
                'employee' => $employee->id,
                'run' => $run->id,
                'payslip' => (string) $run->payslips()->value('id'),
            ];
        });

        $intruder = $this->makeUser('intruder-payroll@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder Ltd');

        app(WorkspaceContext::class)->forget();

        return [$intruder, $victimWorkspace, $intruderWorkspace, $ids];
    }
}
