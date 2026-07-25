<?php

declare(strict_types=1);

namespace Modules\Alerts\Scanners;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Modules\Alerts\Contracts\AlertScanner;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Support\AlertCandidate;
use Modules\Banking\Models\LoanInstallment;
use Modules\Core\Support\WorkspaceContext;

/**
 * Loan instalments coming due inside the rule's lead time.
 *
 * A part-paid instalment still owes something, so `status` alone is not enough
 * to decide — remaining() is.
 */
final readonly class InstallmentDueScanner implements AlertScanner
{
    public function __construct(private WorkspaceContext $context) {}

    public function type(): string
    {
        return AlertRule::TYPE_INSTALLMENT_DUE;
    }

    public function scan(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $horizon = $now->addDays($rule->leadDays())->endOfDay();
        $currency = $this->context->baseCurrency();

        $installments = LoanInstallment::query()
            ->where('status', '!=', LoanInstallment::STATUS_PAID)
            ->whereDate('due_date', '<=', $horizon)
            ->orderBy('due_date')
            ->get();

        foreach ($installments as $installment) {
            if ($installment->isSettled()) {
                continue;
            }

            $dueDate = CarbonImmutable::instance($installment->due_date);

            yield new AlertCandidate(
                type: $this->type(),
                dedupeKey: "loan_installment:{$installment->id}:due:{$dueDate->format('Y-m-d')}",
                payload: [
                    'installment_id' => $installment->id,
                    'loan_id' => $installment->loan_id,
                    'number' => $installment->number,
                    'remaining' => Money::of($installment->remaining(), $currency),
                    'due_date' => $dueDate->toDateString(),
                    'days_ahead' => $now->startOfDay()->diffInDays($dueDate->startOfDay(), false),
                ],
                scheduledAt: $now,
            );
        }
    }
}
