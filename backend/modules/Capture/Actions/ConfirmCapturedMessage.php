<?php

declare(strict_types=1);

namespace Modules\Capture\Actions;

use Modules\AI\Actions\ConfirmDraft;
use Modules\Capture\Exceptions\CaptureException;
use Modules\Capture\Models\CaptureMessage;
use Modules\Ledger\Models\Transaction;

/**
 * The user's decision on a captured message.
 *
 * The money is still written by Modules\AI\Actions\ConfirmDraft — there is one
 * door from a suggestion to the ledger and this is not a second one. What this
 * adds is provenance: `transactions.source` names sms, email and qr, and a
 * transaction that came from a bank SMS should say so rather than claim to have
 * been typed by hand.
 */
final readonly class ConfirmCapturedMessage
{
    public function __construct(private ConfirmDraft $confirm) {}

    /** @param array<string, mixed> $overrides */
    public function handle(CaptureMessage $message, array $overrides = []): Transaction
    {
        $draft = $message->draft;

        if ($draft === null) {
            throw CaptureException::nothingToConfirm($message->id);
        }

        $transaction = $this->confirm->handle($draft, $overrides);

        $transaction->forceFill([
            'source' => $message->transactionSource(),
            'source_meta' => array_merge((array) $transaction->source_meta, [
                'capture_message_id' => $message->id,
                'capture_channel' => $message->channel,
            ]),
        ])->save();

        $message->forceFill(['transaction_id' => $transaction->id])->save();

        return $transaction;
    }
}
