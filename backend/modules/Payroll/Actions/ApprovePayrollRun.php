<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollRun;

/**
 * Approves a run: draft → approved, and posts it to the ledger.
 *
 * Approving is the step at which payroll becomes real money, so it is also the
 * step that has to survive being asked for twice. The run is locked, the
 * totals are re-derived from the payslips before anything is posted — approving
 * numbers that no longer match the payslips would be worse than refusing — and
 * an already-approved run is returned as it stands rather than posted again.
 */
final readonly class ApprovePayrollRun
{
    public function __construct(private PostPayrollRun $post) {}

    public function handle(PayrollRun|string $run, ?User $approver = null): PayrollRun
    {
        $runId = $run instanceof PayrollRun ? $run->id : $run;

        return DB::transaction(function () use ($runId, $approver): PayrollRun {
            $run = PayrollRun::query()
                ->lockForUpdate()
                ->findOr($runId, callback: fn () => throw PayrollException::runNotFound($runId));

            if ($run->isPaid()) {
                throw PayrollException::runIsPaid($run->reference);
            }

            if ($run->isApproved()) {
                // Already done. Returning it is the honest answer to "approve
                // this", and it is what stops a retry from double-posting.
                return $run->fresh(['payslips.lines', 'netTransaction', 'liabilityTransaction']) ?? $run;
            }

            $run->recalculateTotals();

            $run->status = PayrollRun::STATUS_APPROVED;
            $run->approved_at = CarbonImmutable::now();
            $run->approved_by = $approver?->id;
            $run->save();

            $this->post->handle($run);

            return $run->fresh(['payslips.lines', 'netTransaction', 'liabilityTransaction']) ?? $run;
        });
    }
}
