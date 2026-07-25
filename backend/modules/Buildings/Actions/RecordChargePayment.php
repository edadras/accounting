<?php

declare(strict_types=1);

namespace Modules\Buildings\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Support\Facades\DB;
use Modules\Buildings\Exceptions\BuildingsException;
use Modules\Buildings\Models\BuildingCharge;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;

/**
 * Records a resident's payment against a charge and moves the money into the
 * building's fund.
 *
 * The charge row and the ledger posting are written in one database
 * transaction: a charge marked paid with no money behind it, or money in the
 * fund that no charge accounts for, are both states no report can recover from.
 */
final readonly class RecordChargePayment
{
    public function __construct(private RecordTransaction $record) {}

    public function handle(
        BuildingCharge $charge,
        Money $amount,
        ?\DateTimeInterface $paidAt = null,
        ?string $reference = null,
        ?string $idempotencyKey = null,
    ): BuildingCharge {
        if (! $amount->isPositive()) {
            throw BuildingsException::nonPositiveAmount();
        }

        $chargeCurrency = Currency::of($charge->currency);

        if (! $amount->currency->equals($chargeCurrency)) {
            throw BuildingsException::currencyMismatch($amount->currency->code, $chargeCurrency->code);
        }

        if ($charge->isSettled()) {
            throw BuildingsException::chargeAlreadySettled($charge->id);
        }

        $remaining = $charge->remainingMoney();

        // Refused rather than clamped or taken as a credit: the fund is not a
        // deposit account, and silently accepting more than is owed would make
        // the debtors report disagree with the bank.
        if ($amount->greaterThan($remaining)) {
            throw BuildingsException::overpayment(
                $charge->id,
                $amount->minorUnits,
                $remaining->minorUnits,
                $chargeCurrency->code,
            );
        }

        $building = $charge->building()->first();

        if ($building === null) {
            throw BuildingsException::buildingNotFound($charge->building_id);
        }

        $fund = $building->fundAccount()->first();

        if ($fund === null) {
            throw BuildingsException::fundAccountMissing($building->id);
        }

        if (! $amount->currency->equals(Currency::of($fund->currency))) {
            throw BuildingsException::currencyMismatch($amount->currency->code, $fund->currency);
        }

        return DB::transaction(function () use ($charge, $amount, $building, $fund, $paidAt, $reference, $idempotencyKey): BuildingCharge {
            $unitNo = $charge->unit()->first()->unit_no ?? '?';

            $transaction = $this->record->handle([
                'type' => Transaction::TYPE_INCOME,
                'account_id' => $fund->id,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency->code,
                'occurred_at' => $paidAt ?? now(),
                'description' => "Building charge {$charge->period} — unit {$unitNo}",
                'payee' => $building->name,
                'reference' => $reference,
                'source' => 'api',
                'idempotency_key' => $idempotencyKey,
            ]);

            $paid = $charge->paid_amount + $amount->minorUnits;

            $charge->forceFill([
                'paid_amount' => $paid,
                'status' => $paid >= $charge->amount
                    ? BuildingCharge::STATUS_PAID
                    : BuildingCharge::STATUS_PARTIAL,
                // One column, possibly several instalments: it points at the
                // most recent posting, which is the one that settled the bill.
                // The full trail lives in the ledger, keyed by the fund account.
                'transaction_id' => $transaction->id,
            ])->save();

            return $charge->refresh();
        });
    }
}
