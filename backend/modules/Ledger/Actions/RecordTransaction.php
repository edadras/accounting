<?php

declare(strict_types=1);

namespace Modules\Ledger\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Exceptions\LedgerException;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Entry;
use Modules\Ledger\Models\Transaction;

/**
 * Records a transaction and the entries that back it.
 *
 * Everything happens inside one database transaction: a posting that updated a
 * balance but failed to write its second entry would leave the books wrong in a
 * way no report could detect.
 *
 * @phpstan-type TransactionPayload array{
 *   id?: string,
 *   type: string,
 *   account_id: string,
 *   counter_account_id?: string|null,
 *   category_id?: string|null,
 *   amount: int,
 *   currency: string,
 *   fx_rate?: float|string|null,
 *   occurred_at?: \DateTimeInterface|string|null,
 *   description?: string|null,
 *   notes?: string|null,
 *   payee?: string|null,
 *   reference?: string|null,
 *   tags?: array<string>|null,
 *   source?: string,
 *   source_meta?: array<string, mixed>|null,
 *   latitude?: float|null,
 *   longitude?: float|null,
 *   idempotency_key?: string|null,
 * }
 */
final readonly class RecordTransaction
{
    public function __construct(
        private WorkspaceContext $context,
        private ExchangeRateResolver $rates,
    ) {}

    /**
     * @param  TransactionPayload  $data
     */
    public function handle(array $data): Transaction
    {
        $workspace = $this->context->require();
        $type = $data['type'];

        if (! in_array($type, Transaction::TYPES, true)) {
            throw LedgerException::unknownTransactionType($type);
        }

        // Replaying the same request must not create a second transaction.
        // The client retries freely when a connection drops mid-write, so this
        // is the normal path, not an edge case.
        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = Transaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $account = Account::query()->findOr($data['account_id'], callback: fn () => throw LedgerException::accountNotFound($data['account_id']));

        $currency = Currency::of($data['currency']);
        $amount = new Money((int) $data['amount'], $currency);

        if ($amount->isNegative()) {
            // Direction is carried by `type`, not by the sign. Allowing both
            // would create two ways to express "expense" and guarantee that
            // some report eventually double-counts.
            throw LedgerException::negativeAmount();
        }

        if ($amount->isZero()) {
            throw LedgerException::zeroAmount();
        }

        if (! $amount->currency->equals(Currency::of($account->currency))) {
            throw LedgerException::currencyMismatch($amount->currency->code, $account->currency);
        }

        $counterAccount = null;

        if ($type === Transaction::TYPE_TRANSFER) {
            $counterAccountId = $data['counter_account_id'] ?? null;

            if ($counterAccountId === null) {
                throw LedgerException::transferNeedsCounterAccount();
            }

            if ($counterAccountId === $account->id) {
                throw LedgerException::transferToSameAccount();
            }

            $counterAccount = Account::query()->findOr(
                $counterAccountId,
                callback: fn () => throw LedgerException::accountNotFound($counterAccountId),
            );
        }

        $baseCurrency = Currency::of($workspace->base_currency);
        $rate = $data['fx_rate'] ?? null;
        $rate = $rate !== null
            ? (string) $rate
            : $this->rates->rate($currency, $baseCurrency);
        $baseAmount = $amount->convertTo($baseCurrency, $rate);

        return DB::transaction(function () use (
            $data, $type, $account, $counterAccount, $amount, $baseAmount,
            $baseCurrency, $rate, $idempotencyKey
        ): Transaction {
            $categoryId = $this->resolveCategoryId($data['category_id'] ?? null, $type);
            $occurredAt = $this->resolveOccurredAt($data['occurred_at'] ?? null);

            $transaction = new Transaction;

            if (! empty($data['id'])) {
                // Client-generated ULID: an offline record keeps the identity it
                // was created with.
                $transaction->id = $data['id'];
            }

            $transaction->fill([
                'type' => $type,
                'account_id' => $account->id,
                'counter_account_id' => $counterAccount?->id,
                'category_id' => $categoryId,
                'amount' => $amount->minorUnits,
                'currency' => $amount->currency->code,
                'fx_rate' => $rate,
                'base_amount' => $baseAmount->minorUnits,
                'base_currency' => $baseCurrency->code,
                'occurred_at' => $occurredAt,
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payee' => $data['payee'] ?? null,
                'reference' => $data['reference'] ?? null,
                'tags' => $data['tags'] ?? null,
                'source' => $data['source'] ?? 'manual',
                'source_meta' => $data['source_meta'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $transaction->save();

            $this->postEntries($transaction, $account, $counterAccount, $amount, $baseAmount);

            $account->recalculateBalance();
            $counterAccount?->recalculateBalance();

            $saved = $transaction->fresh(['account', 'counterAccount', 'category', 'entries']);

            if ($saved === null) {
                // The row we just wrote is gone: something outside this
                // transaction deleted it. Rolling back is the only safe answer
                // — returning the in-memory copy would report entries the
                // ledger no longer holds.
                throw LedgerException::transactionVanished($transaction->id);
            }

            return $saved;
        });
    }

    /**
     * Writes the double-entry postings.
     *
     * Sign convention: a debit increases an account, a credit decreases it.
     *   income   → debit the account (money arrives)
     *   expense  → credit the account (money leaves)
     *   transfer → credit the source, debit the destination
     *
     * For a same-currency transfer the two base amounts cancel exactly. For a
     * cross-currency transfer they do not, and forcing them to would fabricate
     * an amount — so the destination leg is converted at its own rate and the
     * difference is a real FX gain or loss rather than a rounding fudge.
     */
    private function postEntries(
        Transaction $transaction,
        Account $account,
        ?Account $counterAccount,
        Money $amount,
        Money $baseAmount,
    ): void {
        $occurredAt = $transaction->occurred_at;

        $write = function (Account $target, string $direction, Money $value, Money $base) use ($transaction, $occurredAt): void {
            Entry::query()->create([
                'transaction_id' => $transaction->id,
                'account_id' => $target->id,
                'direction' => $direction,
                'amount' => $value->minorUnits,
                'currency' => $value->currency->code,
                'base_amount' => $base->minorUnits,
                'occurred_at' => $occurredAt,
            ]);
        };

        match ($transaction->type) {
            Transaction::TYPE_INCOME => $write($account, Entry::DEBIT, $amount, $baseAmount),
            Transaction::TYPE_EXPENSE => $write($account, Entry::CREDIT, $amount, $baseAmount),
            Transaction::TYPE_TRANSFER => $this->postTransfer(
                $write, $account, $counterAccount, $amount, $baseAmount
            ),
            // Without this arm an unrecognised type would silently write no
            // entries at all: a transaction on the books backed by nothing.
            default => throw LedgerException::unknownTransactionType($transaction->type),
        };
    }

    private function postTransfer(
        callable $write,
        Account $source,
        ?Account $destination,
        Money $amount,
        Money $baseAmount,
    ): void {
        if ($destination === null) {
            throw LedgerException::transferNeedsCounterAccount();
        }

        $write($source, Entry::CREDIT, $amount, $baseAmount);

        $destinationCurrency = Currency::of($destination->currency);

        if ($destinationCurrency->equals($amount->currency)) {
            $write($destination, Entry::DEBIT, $amount, $baseAmount);

            return;
        }

        // Cross-currency: convert through the workspace's base currency so both
        // legs are valued consistently against the same yardstick.
        $rate = $this->rates->rate($baseAmount->currency, $destinationCurrency);
        $received = $baseAmount->convertTo($destinationCurrency, $rate);

        $write($destination, Entry::DEBIT, $received, $baseAmount);
    }

    private function resolveCategoryId(?string $categoryId, string $type): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        if ($type === Transaction::TYPE_TRANSFER) {
            // A transfer moves money between the user's own accounts; it is not
            // spending, and letting it carry a category would inflate every
            // "where did my money go" report.
            return null;
        }

        Category::query()->findOr(
            $categoryId,
            callback: fn () => throw LedgerException::categoryNotFound($categoryId),
        );

        return $categoryId;
    }

    private function resolveOccurredAt(mixed $value): \DateTimeInterface
    {
        return match (true) {
            $value === null => now(),
            $value instanceof \DateTimeInterface => $value,
            default => new \DateTimeImmutable((string) $value),
        };
    }
}
