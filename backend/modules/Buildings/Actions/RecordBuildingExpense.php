<?php

declare(strict_types=1);

namespace Modules\Buildings\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Support\Facades\DB;
use Modules\Buildings\Exceptions\BuildingsException;
use Modules\Buildings\Models\Building;
use Modules\Buildings\Models\BuildingExpense;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;

/**
 * Spends money out of the building's fund and posts the matching ledger
 * expense, so the fund balance and the expense list can never disagree.
 */
final readonly class RecordBuildingExpense
{
    public function __construct(private RecordTransaction $record) {}

    public function handle(
        Building $building,
        Money $amount,
        ?string $categoryId = null,
        ?\DateTimeInterface $occurredAt = null,
        ?string $description = null,
    ): BuildingExpense {
        if (! $amount->isPositive()) {
            throw BuildingsException::nonPositiveAmount();
        }

        $fund = $building->fundAccount()->first();

        if ($fund === null) {
            throw BuildingsException::fundAccountMissing($building->id);
        }

        if (! $amount->currency->equals(Currency::of($fund->currency))) {
            throw BuildingsException::currencyMismatch($amount->currency->code, $fund->currency);
        }

        $occurredAt ??= now();

        return DB::transaction(function () use ($building, $fund, $amount, $categoryId, $occurredAt, $description): BuildingExpense {
            $transaction = $this->record->handle([
                'type' => Transaction::TYPE_EXPENSE,
                'account_id' => $fund->id,
                'category_id' => $categoryId,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency->code,
                'occurred_at' => $occurredAt,
                'description' => $description,
                'payee' => $building->name,
                'source' => 'api',
            ]);

            return BuildingExpense::query()->create([
                'building_id' => $building->id,
                'category_id' => $categoryId,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency->code,
                'occurred_at' => $occurredAt,
                'description' => $description,
                'transaction_id' => $transaction->id,
            ]);
        });
    }
}
