<?php

declare(strict_types=1);

namespace Modules\Alerts\Scanners;

use Carbon\CarbonImmutable;
use Modules\Alerts\Contracts\AlertScanner;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Support\AlertCandidate;
use Modules\Banking\Models\Check;

/**
 * Cheques coming due inside the rule's lead time.
 *
 * A cheque that has cleared, bounced or been voided will never move money
 * again, so warning about it is noise.
 */
final class CheckDueScanner implements AlertScanner
{
    public function type(): string
    {
        return AlertRule::TYPE_CHECK_DUE;
    }

    public function scan(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $horizon = $now->addDays($rule->leadDays())->endOfDay();

        $checks = Check::query()
            ->whereNotIn('status', [Check::STATUS_CLEARED, ...Check::TERMINAL_STATUSES])
            ->whereDate('due_date', '<=', $horizon)
            ->orderBy('due_date')
            ->get();

        foreach ($checks as $check) {
            $dueDate = CarbonImmutable::instance($check->due_date);

            yield new AlertCandidate(
                type: $this->type(),

                // Keyed on the due date as well as the id: a cheque whose date
                // is moved is genuinely a new thing to be told about.
                dedupeKey: "check:{$check->id}:due:{$dueDate->format('Y-m-d')}",
                payload: [
                    'check_id' => $check->id,
                    'check_number' => $check->check_number,
                    'direction' => $check->direction,
                    'party_name' => $check->party_name,
                    'amount' => $check->money(),
                    'due_date' => $dueDate->toDateString(),
                    'days_ahead' => $now->startOfDay()->diffInDays($dueDate->startOfDay(), false),
                ],
                scheduledAt: $now,
            );
        }
    }
}
