<?php

declare(strict_types=1);

namespace Modules\Family\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Support\Facades\DB;
use Modules\Family\Exceptions\FamilyException;
use Modules\Family\Models\AllowancePayment;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Support\Period;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;

/**
 * Pays a member their allowance for one month by moving the money for real.
 *
 * The payment is an ordinary ledger transfer from the payer's account to the
 * member's, so both balances move and every existing report already understands
 * it. `allowance_payments` records that the month has been dealt with; it is
 * not a second copy of the money.
 */
final readonly class PayAllowance
{
    public function __construct(private RecordTransaction $record) {}

    public function handle(
        FamilyMember $member,
        FamilyMember $payer,
        string $period,
        ?Money $amount = null,
        ?\DateTimeInterface $paidAt = null,
    ): AllowancePayment {
        $month = Period::of($period);

        if ($member->id === $payer->id) {
            throw FamilyException::payerIsRecipient($member->id);
        }

        $amount ??= $member->allowance() ?? throw FamilyException::noAllowanceConfigured($member->id);

        if (! $amount->isPositive()) {
            throw FamilyException::nonPositiveAllowance();
        }

        $payerAccount = $payer->account()->first() ?? throw FamilyException::memberHasNoAccount($payer->id);
        $memberAccount = $member->account()->first() ?? throw FamilyException::memberHasNoAccount($member->id);

        if (! $amount->currency->equals(Currency::of($payerAccount->currency))) {
            throw FamilyException::currencyMismatch($amount->currency->code, $payerAccount->currency);
        }

        // Checked before the write as well as by the unique index, so the caller
        // gets a stable error code rather than a driver-specific constraint
        // violation.
        if ($this->alreadyPaid($member, $month)) {
            throw FamilyException::allowanceAlreadyPaid($member->id, $month->key);
        }

        $paidAt ??= now();

        return DB::transaction(function () use ($member, $payer, $month, $amount, $paidAt, $payerAccount, $memberAccount): AllowancePayment {
            $transaction = $this->record->handle([
                'type' => Transaction::TYPE_TRANSFER,
                'account_id' => $payerAccount->id,
                'counter_account_id' => $memberAccount->id,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency->code,
                'occurred_at' => $paidAt,
                'description' => "Allowance {$month->key} — {$member->display_name}",
                'tags' => [$member->tag()],
                'source' => 'api',

                // Replaying the request cannot produce a second transfer even if
                // the allowance row was rolled back on a previous attempt.
                'idempotency_key' => "allowance:{$member->id}:{$month->key}",
            ]);

            return AllowancePayment::query()->create([
                'member_id' => $member->id,
                'payer_member_id' => $payer->id,
                'period' => $month->key,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency->code,
                'transaction_id' => $transaction->id,
                'paid_at' => $paidAt,
            ]);
        });
    }

    private function alreadyPaid(FamilyMember $member, Period $period): bool
    {
        return AllowancePayment::query()
            ->where('member_id', $member->id)
            ->where('period', $period->key)
            ->exists();
    }
}
