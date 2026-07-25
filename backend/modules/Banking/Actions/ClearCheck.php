<?php

declare(strict_types=1);

namespace Modules\Banking\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Banking\Exceptions\BankingException;
use Modules\Banking\Models\Check;
use Modules\Ledger\Actions\RecordTransaction;

/**
 * Clears a cheque and posts the money movement it finally represents.
 *
 * A cheque is a promise until the day it clears, which is why nothing reaches
 * the ledger before this point. Clearing is idempotent twice over: the cheque's
 * own status is checked first, and the posting carries an idempotency key
 * derived from the cheque id, so even a racing second call cannot produce a
 * second transaction.
 */
final readonly class ClearCheck
{
    public function __construct(private RecordTransaction $record) {}

    public function handle(Check $check, ?\DateTimeInterface $clearedAt = null): Check
    {
        if ($check->isCleared()) {
            return $check;
        }

        if ($check->isTerminal()) {
            throw BankingException::checkNotClearable((string) $check->status);
        }

        if (! in_array($check->direction, Check::DIRECTIONS, true)) {
            throw BankingException::unknownCheckDirection((string) $check->direction);
        }

        $clearedAt ??= now();

        return DB::transaction(function () use ($check, $clearedAt): Check {
            $transaction = $this->record->handle([
                'type' => $check->ledgerType(),
                'account_id' => $check->account_id,
                'amount' => $check->amount,
                'currency' => $check->currency,
                'occurred_at' => $clearedAt,
                'description' => 'Cheque '.$check->check_number,
                'payee' => $check->party_name,
                'reference' => $check->check_number,
                'source' => 'manual',
                'idempotency_key' => 'check:'.$check->id,
            ]);

            $check->forceFill([
                'status' => Check::STATUS_CLEARED,
                'transaction_id' => $transaction->id,
                'cleared_at' => $clearedAt,
                'base_amount' => $transaction->base_amount,
            ])->save();

            return $check->refresh();
        });
    }
}
