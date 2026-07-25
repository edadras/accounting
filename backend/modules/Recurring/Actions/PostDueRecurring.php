<?php

declare(strict_types=1);

namespace Modules\Recurring\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Models\RecurringRule;

/**
 * Posts every occurrence a rule owes up to now, in the active workspace.
 *
 * Two independent guards stop a second run on the same day from posting
 * anything twice: `next_run_at` has already moved past today, and the
 * transaction carries an idempotency key derived from the rule and the
 * occurrence date, so even a rule whose advance was rolled back cannot produce
 * a duplicate.
 */
final readonly class PostDueRecurring
{
    /**
     * A rule left unattended for years must not spend an unbounded amount of
     * time — or write an unbounded number of rows — the first time anyone runs
     * the poster. Whatever is left stays due for the next run.
     */
    private const MAX_OCCURRENCES_PER_RUN = 60;

    public function __construct(private RecordTransaction $record) {}

    /**
     * @return list<Transaction> the transactions posted, oldest first
     */
    public function handle(?\DateTimeInterface $at = null): array
    {
        $moment = $at === null ? CarbonImmutable::now() : CarbonImmutable::instance(
            $at instanceof \DateTimeImmutable ? $at : \DateTimeImmutable::createFromInterface($at)
        );

        $rules = RecurringRule::query()
            ->due($moment)
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->get();

        $posted = [];

        foreach ($rules as $rule) {
            foreach ($this->postRule($rule, $moment) as $transaction) {
                $posted[] = $transaction;
            }
        }

        return $posted;
    }

    /** @return list<Transaction> */
    private function postRule(RecurringRule $rule, CarbonImmutable $at): array
    {
        $posted = [];
        $guard = 0;

        while (
            $rule->next_run_at !== null
            && $rule->next_run_at <= $at
            && $guard++ < self::MAX_OCCURRENCES_PER_RUN
        ) {
            $due = CarbonImmutable::instance($rule->next_run_at);

            if ($rule->hasEnded($due)) {
                $rule->next_run_at = null;
                break;
            }

            $posted[] = DB::transaction(function () use ($rule, $due): Transaction {
                $transaction = $this->record->handle($rule->payloadFor($due));

                // Advanced inside the same database transaction as the posting:
                // a rule that moved on without its transaction would silently
                // skip a month.
                $rule->next_run_at = $rule->occurrenceAfter($due);
                $rule->last_run_at = $due;
                $rule->save();

                return $transaction;
            });
        }

        if ($rule->isDirty('next_run_at')) {
            $rule->save();
        }

        return $posted;
    }
}
