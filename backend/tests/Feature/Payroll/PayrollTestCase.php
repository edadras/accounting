<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use Illuminate\Foundation\Application;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Account;
use Modules\Payroll\Actions\RegisterEmployee;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Providers\PayrollServiceProvider;
use Modules\Payroll\Support\CompensationProrator;
use Tests\Feature\LedgerTestCase;

/**
 * Shared scaffolding for the payroll tests.
 *
 * The module registers itself for the test run. Wiring a module into
 * bootstrap/providers.php is the deploying application's decision; these tests
 * must prove the module works either way, so they never depend on that file
 * having been edited.
 */
abstract class PayrollTestCase extends LedgerTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->providerIsLoaded(PayrollServiceProvider::class)) {
            $app->register(PayrollServiceProvider::class);
        }

        return $app;
    }

    protected function payrollWorkspace(string $email, string $currency = 'TRY'): Workspace
    {
        return $this->makeWorkspace($this->makeUser($email), 'Expandia', $currency);
    }

    /**
     * Hires somebody inside the active workspace.
     *
     * Defaults chosen so the arithmetic in the tests is never round: 1_234_567
     * minor units does not divide by twelve, by seven, or by a percentage.
     */
    protected function hire(
        string $name,
        int $amount = 1234567,
        string $currency = 'TRY',
        string $period = CompensationProrator::PERIOD_MONTHLY,
        string $startedOn = '2020-01-01',
        ?string $endedOn = null,
        string $country = 'XX',
        ?string $status = null,
    ): Employee {
        $payload = [
            'name' => $name,
            'country' => $country,
            'started_on' => $startedOn,
            'compensation' => [
                'amount' => $amount,
                'currency' => $currency,
                'period' => $period,
                'effective_from' => $startedOn,
            ],
        ];

        if ($endedOn !== null) {
            $payload['ended_on'] = $endedOn;
        }

        if ($status !== null) {
            $payload['status'] = $status;
        }

        return app(RegisterEmployee::class)->handle($payload);
    }

    /** @param array<string, mixed> $overrides */
    protected function runPayload(Account $account, array $overrides = []): array
    {
        return array_merge([
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'account_id' => $account->id,
            'currency' => $account->currency,
        ], $overrides);
    }

    protected function assertRunReconciles(PayrollRun $run): void
    {
        $this->assertSame(
            $run->gross_total - $run->deduction_total,
            $run->net_total,
            'A run must pay out exactly gross minus deductions.',
        );
    }
}
