<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;
use Modules\Payroll\Models\PayrollRun;

/**
 * Puts an approved run into the books, once.
 *
 * Two postings, both expenses against the account the payroll leaves from:
 *   • the net pay, the money the employees actually receive;
 *   • the withholdings and employer contributions, the money the employer owes
 *     onward to a tax authority.
 * Together they come to gross + employer contributions, which is what the run
 * genuinely costs.
 *
 * **Idempotence.** Approving twice is not an exotic case: a client retries when
 * a connection drops, and a person clicks twice. So there are two independent
 * guards. This action returns early if the run already carries its transaction
 * ids, and every posting also carries a deterministic `idempotency_key` derived
 * from the run — meaning that even if the first guard were bypassed,
 * RecordTransaction returns the transaction it already wrote instead of writing
 * a second one. Payroll is the last place where "probably only once" is good
 * enough.
 */
final readonly class PostPayrollRun
{
    public function __construct(private RecordTransaction $record) {}

    public function handle(PayrollRun $run): PayrollRun
    {
        if ($run->isPosted()) {
            return $run;
        }

        $net = $run->net();
        $liabilities = $run->liabilities();

        // A zero leg is not posted at all: the ledger refuses a zero
        // transaction, and rightly — it would be a row that means nothing.
        if ($net->isPositive()) {
            $run->net_transaction_id = $this->post(
                $run,
                $net->minorUnits,
                'Payroll '.$run->reference.' — net pay',
                'net',
            )->id;
        }

        if ($liabilities->isPositive()) {
            $run->liability_transaction_id = $this->post(
                $run,
                $liabilities->minorUnits,
                'Payroll '.$run->reference.' — withholdings and employer contributions',
                'liabilities',
            )->id;
        }

        $run->save();

        return $run;
    }

    private function post(PayrollRun $run, int $amount, string $description, string $leg): Transaction
    {
        return $this->record->handle([
            'type' => Transaction::TYPE_EXPENSE,
            'account_id' => $run->account_id,
            'category_id' => $run->category_id,
            'amount' => $amount,
            'currency' => $run->currency,
            'occurred_at' => $run->pay_date ?? $run->period_end,
            'description' => $description,
            'reference' => $run->reference,
            'source' => 'api',
            'source_meta' => ['payroll_run_id' => $run->id, 'leg' => $leg],

            // Deterministic, so a replay of the same run's approval finds the
            // transaction that already exists rather than writing another.
            'idempotency_key' => 'payroll-run:'.$run->id.':'.$leg,
        ]);
    }
}
