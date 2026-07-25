<?php

declare(strict_types=1);

namespace Modules\Family\Queries;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Modules\Core\Support\WorkspaceContext;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Support\Period;
use Modules\Ledger\Models\Transaction;

/**
 * What each member of the household spent in a month, against the ceiling they
 * were given.
 *
 * Spending is attributed by the member tag a transaction carries, so the Ledger
 * table stays unaware that families exist. Only expenses count: an allowance
 * arriving in a child's account is a transfer between the household's own
 * accounts, and counting it would exhaust the child's cap the moment they were
 * paid.
 */
final readonly class MemberSpending
{
    public function __construct(private WorkspaceContext $context) {}

    /** @return array<string, mixed> */
    public function forMember(FamilyMember $member, Period $period): array
    {
        $base = Currency::of($this->context->baseCurrency());
        $spent = $this->spent($member, $period, $base);
        $cap = $member->spendingCap();

        // A cap denominated in something other than the books' base currency
        // cannot be compared against a base-currency total without inventing a
        // rate, so the comparison is reported as unavailable rather than wrong.
        $comparable = $cap !== null && $cap->currency->equals($base);

        return [
            'member_id' => $member->id,
            'display_name' => $member->display_name,
            'role' => $member->role,
            'period' => $period->key,
            'spent' => $spent,
            'cap' => $cap,
            'remaining' => $comparable ? $cap->minus($spent) : null,
            'percentage' => $comparable && $cap->isPositive()
                ? round($spent->minorUnits / $cap->minorUnits * 100, 2)
                : null,
            'is_over_cap' => $comparable && $spent->greaterThan($cap),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function forPeriod(Period $period): array
    {
        return FamilyMember::query()
            ->orderBy('display_name')
            ->orderBy('id')
            ->get()
            ->map(fn (FamilyMember $member): array => $this->forMember($member, $period))
            ->all();
    }

    private function spent(FamilyMember $member, Period $period, Currency $base): Money
    {
        $total = Transaction::query()
            ->ofType(Transaction::TYPE_EXPENSE)
            ->between($period->start, $period->end)
            ->whereJsonContains('tags', $member->tag())

            // base_amount is frozen against the base currency of the day. If the
            // workspace has since switched, those older rows are denominated in
            // something else and summing them would add two different yardsticks.
            ->where('base_currency', $base->code)
            ->sum('base_amount');

        return Money::of((int) $total, $base);
    }
}
