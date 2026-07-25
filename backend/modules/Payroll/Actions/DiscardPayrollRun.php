<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollRun;

/**
 * Throws away a draft.
 *
 * Only a draft: once a run has been approved it is in the ledger, and the way
 * to undo something that is in the ledger is a reversing entry, not a delete
 * that leaves the books remembering a payroll nobody can find.
 */
final readonly class DiscardPayrollRun
{
    public function handle(PayrollRun|string $run): PayrollRun
    {
        $runId = $run instanceof PayrollRun ? $run->id : $run;

        return DB::transaction(function () use ($runId): PayrollRun {
            $run = PayrollRun::query()
                ->lockForUpdate()
                ->findOr($runId, callback: fn () => throw PayrollException::runNotFound($runId));

            // A paid run says so specifically: "not a draft" would be true but
            // would send the caller looking for the wrong thing.
            $run->assertNotPaid();

            if (! $run->isDraft()) {
                throw PayrollException::onlyADraftMayBeDiscarded($run->reference, $run->status);
            }

            foreach ($run->payslips()->get() as $payslip) {
                $payslip->lines()->delete();
                $payslip->delete();
            }

            $run->delete();

            return $run;
        });
    }
}
