<?php

declare(strict_types=1);

namespace Tests\Feature\Capture;

use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiDraft;
use Modules\Capture\Models\CaptureMessage;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * The natural-language channel of docs/02-modules.md §1, reached over HTTP.
 *
 * The parser behind it is the AI module's and is tested there; what is tested
 * here is that the route exists, that it stops at a draft, and that the ledger
 * only moves when the user says so.
 */
final class TextCaptureTest extends CaptureTestCase
{
    private const SENTENCE = 'دیروز ۳۵۰ لیر برای شام پرداخت کردم';

    #[Test]
    public function a_sentence_becomes_a_draft_and_writes_no_transaction(): void
    {
        [$user, $workspace] = $this->world('text@example.test');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/capture/text', ['text' => self::SENTENCE], $this->headers($workspace));

        $response->assertCreated();
        $response->assertJsonPath('data.channel', CaptureMessage::CHANNEL_TEXT);
        $response->assertJsonPath('data.status', CaptureMessage::STATUS_PARSED);
        $response->assertJsonPath('data.needs_confirmation', true);
        $response->assertJsonPath('data.draft.status', AiDraft::STATUS_PENDING);
        $response->assertJsonPath('data.draft.draft.amount', 35000);
        $response->assertJsonPath('data.draft.draft.currency', 'TRY');
        $response->assertJsonPath('data.draft.draft.occurred_at', '2026-07-24T00:00:00+00:00');

        $this->assertSame(
            0,
            $this->inWorkspace($workspace, fn () => Transaction::query()->count()),
            'Capture proposes. Nothing here may reach the ledger.',
        );
    }

    #[Test]
    public function confirming_the_draft_is_what_records_the_transaction(): void
    {
        [$user, $workspace] = $this->world('textconfirm@example.test');
        Sanctum::actingAs($user);

        $messageId = $this->postJson('/api/v1/capture/text', ['text' => self::SENTENCE], $this->headers($workspace))
            ->json('data.id');

        $this->postJson("/api/v1/capture/messages/{$messageId}/confirm", [], $this->headers($workspace))
            ->assertCreated()
            ->assertJsonPath('data.amount.value', 35000);

        $transaction = $this->inWorkspace($workspace, fn () => Transaction::query()->sole());

        $this->assertSame(35000, $transaction->amount);
        $this->assertSame(Transaction::TYPE_EXPENSE, $transaction->type);
        $this->assertSame('شام', $transaction->description);
        $this->assertSame($messageId, $transaction->source_meta['capture_message_id']);

        $message = $this->inWorkspace($workspace, fn () => CaptureMessage::query()->findOrFail($messageId));
        $this->assertSame($transaction->id, $message->transaction_id);
        $this->assertSame(AiDraft::STATUS_CONFIRMED, $message->draft->status);
    }

    #[Test]
    public function a_sentence_with_no_amount_in_it_is_kept_rather_than_guessed_at(): void
    {
        [$user, $workspace] = $this->world('noamount@example.test');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/capture/text', ['text' => 'رفتم بیرون قدم زدم'], $this->headers($workspace))
            ->assertCreated()
            ->assertJsonPath('data.status', CaptureMessage::STATUS_UNPARSED)
            ->assertJsonPath('data.reason', 'ai_no_amount_found')
            ->assertJsonPath('data.draft', null);

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => AiDraft::query()->count()));
    }

    #[Test]
    public function a_workspace_with_ai_switched_off_is_refused_at_the_edge(): void
    {
        [$user, $workspace] = $this->world('textoff@example.test');
        $workspace->forceFill(['settings' => ['ai' => ['enabled' => false]]])->save();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/capture/text', ['text' => self::SENTENCE], $this->headers($workspace))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ai_disabled');
    }
}
