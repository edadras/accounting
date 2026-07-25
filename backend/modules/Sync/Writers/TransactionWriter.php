<?php

declare(strict_types=1);

namespace Modules\Sync\Writers;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Exceptions\SyncException;
use Modules\Sync\Support\SyncEntity;

/**
 * Writes transactions through the ledger rather than straight at the table.
 *
 * A transaction is not just a row: it owns the double-entry postings that back
 * it and the cached balances those postings feed. Writing the row alone would
 * leave the books internally inconsistent in a way no report could detect, so
 * creation goes through RecordTransaction and edits keep the entries in step.
 */
final class TransactionWriter extends AttributeWriter
{
    /** Without these RecordTransaction has nothing to post. */
    private const REQUIRED_ON_CREATE = ['type', 'account_id', 'amount', 'currency'];

    public function __construct(private readonly RecordTransaction $ledger) {}

    public function create(SyncEntity $entity, string $id, array $attributes, int $version): Model
    {
        $attributes = $entity->writable($attributes);

        $missing = array_values(array_diff(self::REQUIRED_ON_CREATE, array_keys(array_filter(
            $attributes,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ))));

        if ($missing !== []) {
            throw SyncException::incompletePayload($entity->key, $missing);
        }

        $transaction = $this->ledger->handle(['id' => $id] + $attributes);

        if ((int) $transaction->version !== $version) {
            $transaction->version = $version;
            $transaction->save();
        }

        return $transaction;
    }

    public function update(SyncEntity $entity, Model $model, array $attributes, int $version): Model
    {
        $previousAccountIds = $this->accountIds($model);

        $transaction = parent::update($entity, $model, $this->withBaseAmount($model, $attributes), $version);

        $this->restampEntries($transaction, $previousAccountIds);
        $this->recalculate([...$previousAccountIds, ...$this->accountIds($transaction)]);

        return $transaction;
    }

    public function delete(SyncEntity $entity, Model $model, int $version): void
    {
        $accountIds = $this->accountIds($model);

        // Mirrors the REST delete: the postings go with the transaction,
        // otherwise the balances keep counting money that is no longer there.
        $model->entries()->delete();

        parent::delete($entity, $model, $version);

        $this->recalculate($accountIds);
    }

    /**
     * Re-derives the base amount when the device changed the amount but not the
     * base — the fields are independent on the wire, and a base amount left
     * behind would quietly skew every report drawn in the base currency.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withBaseAmount(Model $model, array $attributes): array
    {
        if (! array_key_exists('amount', $attributes) || array_key_exists('base_amount', $attributes)) {
            return $attributes;
        }

        $currency = (string) ($attributes['currency'] ?? $model->currency);
        $rate = (string) ($attributes['fx_rate'] ?? $model->fx_rate);

        $attributes['base_amount'] = Money::of((int) $attributes['amount'], $currency)
            ->convertTo(Currency::of((string) $model->base_currency), $rate)
            ->minorUnits;

        return $attributes;
    }

    /** @param  list<string>  $previousAccountIds */
    private function restampEntries(Transaction $transaction, array $previousAccountIds): void
    {
        [$previousSource, $previousCounter] = $previousAccountIds + [null, null];

        foreach ($transaction->entries()->get() as $entry) {
            if ($entry->account_id === $previousSource) {
                $entry->account_id = $transaction->account_id;
            } elseif ($entry->account_id === $previousCounter && $transaction->counter_account_id !== null) {
                $entry->account_id = $transaction->counter_account_id;
            }

            $entry->occurred_at = $transaction->occurred_at;

            // Only the leg denominated in the transaction's own currency can be
            // re-derived from it. The far leg of a cross-currency transfer was
            // booked at its own rate, and recomputing it here would fabricate an
            // amount — that arithmetic belongs to RecordTransaction.
            if ($entry->currency === $transaction->currency) {
                $entry->amount = (int) $transaction->amount;
                $entry->base_amount = (int) $transaction->base_amount;
            }

            $entry->save();
        }
    }

    /** @return list<string> */
    private function accountIds(Model $model): array
    {
        return array_values(array_filter([$model->account_id, $model->counter_account_id]));
    }

    /** @param  list<string>  $accountIds */
    private function recalculate(array $accountIds): void
    {
        foreach (array_unique($accountIds) as $accountId) {
            Account::query()->find($accountId)?->recalculateBalance();
        }
    }
}
