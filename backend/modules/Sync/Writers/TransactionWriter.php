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

        $transaction = $this->ledger->handle($this->ledgerPayload($entity, $id, $attributes));

        if ($transaction->version !== $version) {
            $transaction->version = $version;
            $transaction->save();
        }

        return $transaction;
    }

    public function update(SyncEntity $entity, Model $model, array $attributes, int $version): Model
    {
        $transaction = $this->transaction($entity, $model);
        $previousAccountIds = $this->accountIds($transaction);

        parent::update($entity, $transaction, $this->withBaseAmount($transaction, $attributes), $version);

        $this->restampEntries($transaction, $previousAccountIds);
        $this->recalculate([...$previousAccountIds, ...$this->accountIds($transaction)]);

        return $transaction;
    }

    public function delete(SyncEntity $entity, Model $model, int $version): void
    {
        $transaction = $this->transaction($entity, $model);
        $accountIds = $this->accountIds($transaction);

        // Mirrors the REST delete: the postings go with the transaction,
        // otherwise the balances keep counting money that is no longer there.
        $transaction->entries()->delete();

        parent::delete($entity, $transaction, $version);

        $this->recalculate($accountIds);
    }

    /**
     * This writer only knows how to keep a transaction's postings in step, so a
     * registry entry that points anything else at it is a configuration error
     * and not something to improvise around.
     */
    private function transaction(SyncEntity $entity, Model $model): Transaction
    {
        return $model instanceof Transaction
            ? $model
            : throw SyncException::misconfiguredEntity($entity->key, $model::class);
    }

    /**
     * The ledger's own payload, built field by field.
     *
     * RecordTransaction re-derives the base amount, the base currency and the
     * rate itself, so what the device sent for those is deliberately not passed
     * on; spelling the shape out also keeps a payload from reaching a ledger
     * field the registry never listed.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{
     *   id: string,
     *   type: string,
     *   account_id: string,
     *   counter_account_id: string|null,
     *   category_id: string|null,
     *   amount: int,
     *   currency: string,
     *   fx_rate: string|null,
     *   occurred_at: \DateTimeInterface|string|null,
     *   description: string|null,
     *   notes: string|null,
     *   payee: string|null,
     *   reference: string|null,
     *   tags: array<string>|null,
     *   source: string,
     * }
     */
    private function ledgerPayload(SyncEntity $entity, string $id, array $attributes): array
    {
        $amount = $attributes['amount'] ?? null;
        $occurredAt = $attributes['occurred_at'] ?? null;
        $tags = $attributes['tags'] ?? null;

        return [
            'id' => $id,
            'type' => $this->required($entity, $attributes, 'type'),
            'account_id' => $this->required($entity, $attributes, 'account_id'),
            'counter_account_id' => self::text($attributes['counter_account_id'] ?? null),
            'category_id' => self::text($attributes['category_id'] ?? null),
            'amount' => is_numeric($amount)
                ? (int) $amount
                : throw SyncException::incompletePayload($entity->key, ['amount']),
            'currency' => $this->required($entity, $attributes, 'currency'),
            'fx_rate' => self::text($attributes['fx_rate'] ?? null),
            'occurred_at' => $occurredAt instanceof \DateTimeInterface ? $occurredAt : self::text($occurredAt),
            'description' => self::text($attributes['description'] ?? null),
            'notes' => self::text($attributes['notes'] ?? null),
            'payee' => self::text($attributes['payee'] ?? null),
            'reference' => self::text($attributes['reference'] ?? null),
            'tags' => is_array($tags) ? array_values(array_filter($tags, is_string(...))) : null,
            'source' => self::text($attributes['source'] ?? null) ?? 'manual',
        ];
    }

    /**
     * A field RecordTransaction cannot post without. REQUIRED_ON_CREATE has
     * already established it is there; this establishes it is a scalar, because
     * a nested array would have passed that check just as happily.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function required(SyncEntity $entity, array $attributes, string $field): string
    {
        $value = self::text($attributes[$field] ?? null);

        return $value !== null && $value !== ''
            ? $value
            : throw SyncException::incompletePayload($entity->key, [$field]);
    }

    /** A wire value read back as text, or null when it is not one. */
    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Re-derives the base amount when the device changed the amount but not the
     * base — the fields are independent on the wire, and a base amount left
     * behind would quietly skew every report drawn in the base currency.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withBaseAmount(Transaction $transaction, array $attributes): array
    {
        if (! array_key_exists('amount', $attributes) || array_key_exists('base_amount', $attributes)) {
            return $attributes;
        }

        $currency = self::text($attributes['currency'] ?? null) ?? $transaction->currency;
        $rate = self::text($attributes['fx_rate'] ?? null) ?? $transaction->fx_rate;

        $attributes['base_amount'] = Money::of((int) $attributes['amount'], $currency)
            ->convertTo(Currency::of($transaction->base_currency), $rate)
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
    private function accountIds(Transaction $transaction): array
    {
        return array_values(array_filter(
            [$transaction->account_id, $transaction->counter_account_id],
            static fn (?string $id): bool => $id !== null && $id !== '',
        ));
    }

    /** @param  list<string>  $accountIds */
    private function recalculate(array $accountIds): void
    {
        foreach (array_unique($accountIds) as $accountId) {
            Account::query()->find($accountId)?->recalculateBalance();
        }
    }
}
