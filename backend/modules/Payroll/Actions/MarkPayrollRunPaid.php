<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollRun;

/**
 * Closes a run: approved → paid.
 *
 * Only an approved run may be paid — a draft has not reached the books, so
 * calling it paid would assert that money moved which never did. After this
 * the run is history and the model refuses every further write.
 */
final readonly class MarkPayrollRunPaid
{
    public function handle(PayrollRun|string $run, ?string $paidAt = null): PayrollRun
    {
        $runId = $run instanceof PayrollRun ? $run->id : $run;

        return DB::transaction(function () use ($runId, $paidAt): PayrollRun {
            $run = PayrollRun::query()
                ->lockForUpdate()
                ->findOr($runId, callback: fn () => throw PayrollException::runNotFound($runId));

            if ($run->isPaid()) {
                // Idempotent: paying an already paid run changes nothing rather
                // than refusing a retry that has already succeeded.
                return $run;
            }

            if (! $run->isApproved()) {
                throw PayrollException::runNotApproved($run->reference, $run->status);
            }

            $run->status = PayrollRun::STATUS_PAID;
            $run->paid_at = $paidAt === null ? CarbonImmutable::now() : CarbonImmutable::parse($paidAt);
            $run->save();

            return $run->fresh(['payslips.lines', 'netTransaction', 'liabilityTransaction']) ?? $run;
        });
    }
}
