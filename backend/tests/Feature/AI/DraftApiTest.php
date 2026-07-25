<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiDraft;
use Modules\AI\Models\AiInsight;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * The API half of the governing rule: a draft is one call, recording it is
 * another, and the second one is the user's.
 */
final class DraftApiTest extends AiTestCase
{
    #[Test]
    public function parsing_returns_a_draft_and_writes_no_transaction(): void
    {
        [$user, $workspace] = $this->world('api@example.test');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/ai/drafts', [
            'text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
        ], $this->headers($workspace));

        $response->assertCreated();
        $response->assertJsonPath('data.status', AiDraft::STATUS_PENDING);
        $response->assertJsonPath('data.needs_confirmation', true);
        $response->assertJsonPath('data.draft.amount', 35000);
        $response->assertJsonPath('data.draft.currency', 'TRY');
        $response->assertJsonPath('data.draft.occurred_at', '2026-07-24T00:00:00+00:00');

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function confirming_a_draft_is_what_records_the_transaction(): void
    {
        [$user, $workspace] = $this->world('confirm@example.test');
        Sanctum::actingAs($user);

        $draftId = $this->postJson('/api/v1/ai/drafts', [
            'text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
        ], $this->headers($workspace))->json('data.id');

        $response = $this->postJson("/api/v1/ai/drafts/{$draftId}/confirm", [], $this->headers($workspace));

        $response->assertCreated();
        $response->assertJsonPath('data.amount.value', 35000);

        $transaction = $this->inWorkspace($workspace, fn () => Transaction::query()->sole());

        $this->assertSame(35000, $transaction->amount);
        $this->assertSame('شام', $transaction->description);

        $meta = $transaction->source_meta;
        $this->assertIsArray($meta);
        $this->assertSame($draftId, $meta['ai_draft_id']);

        $draft = $this->inWorkspace($workspace, fn () => AiDraft::query()->findOrFail((string) $draftId));
        $this->assertSame(AiDraft::STATUS_CONFIRMED, $draft->status);
        $this->assertSame($transaction->id, $draft->transaction_id);
    }

    #[Test]
    public function the_user_may_override_what_was_suggested(): void
    {
        [$user, $workspace] = $this->world('override@example.test');
        Sanctum::actingAs($user);

        $draftId = $this->postJson('/api/v1/ai/drafts', [
            'text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
        ], $this->headers($workspace))->json('data.id');

        $this->postJson("/api/v1/ai/drafts/{$draftId}/confirm", [
            'amount' => 40000,
            'description' => 'شام با دوستان',
            'category_id' => null,
        ], $this->headers($workspace))->assertCreated();

        $transaction = $this->inWorkspace($workspace, fn () => Transaction::query()->sole());

        $this->assertSame(40000, $transaction->amount, 'What the user confirmed wins over what was suggested.');
        $this->assertSame('شام با دوستان', $transaction->description);
        $this->assertNull($transaction->category_id);
    }

    #[Test]
    public function a_draft_cannot_be_confirmed_twice(): void
    {
        [$user, $workspace] = $this->world('twice@example.test');
        Sanctum::actingAs($user);

        $draftId = $this->postJson('/api/v1/ai/drafts', [
            'text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
        ], $this->headers($workspace))->json('data.id');

        $this->postJson("/api/v1/ai/drafts/{$draftId}/confirm", [], $this->headers($workspace))->assertCreated();

        $this->postJson("/api/v1/ai/drafts/{$draftId}/confirm", [], $this->headers($workspace))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ai_draft_already_resolved');

        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function a_discarded_draft_records_nothing(): void
    {
        [$user, $workspace] = $this->world('discard@example.test');
        Sanctum::actingAs($user);

        $draftId = $this->postJson('/api/v1/ai/drafts', [
            'text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
        ], $this->headers($workspace))->json('data.id');

        $this->postJson("/api/v1/ai/drafts/{$draftId}/discard", [], $this->headers($workspace))
            ->assertOk()
            ->assertJsonPath('data.status', AiDraft::STATUS_DISCARDED);

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function another_workspaces_draft_is_not_reachable(): void
    {
        [$owner, $mine] = $this->world('owner@example.test');
        [$stranger] = $this->world('stranger@example.test');

        Sanctum::actingAs($owner);
        $draftId = $this->postJson('/api/v1/ai/drafts', [
            'text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
        ], $this->headers($mine))->json('data.id');

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/ai/drafts/{$draftId}", $this->headers($mine))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'workspace_forbidden');
    }

    #[Test]
    public function the_chat_endpoint_answers_with_its_sources(): void
    {
        [$user, $workspace] = $this->world('chatapi@example.test');
        Sanctum::actingAs($user);

        $this->spend($workspace, $this->wallet($workspace), null, 20_00, '2026-07-10 10:00:00', 'ALPHAMERCHANT');

        $response = $this->postJson('/api/v1/ai/chat', [
            'question' => 'show my transactions',
        ], $this->headers($workspace));

        $response->assertOk();
        $response->assertJsonPath('data.tool_calls', ['get_transactions']);
        $this->assertStringContainsString('ALPHAMERCHANT', (string) $response->json('data.answer'));
        $this->assertSame('get_transactions', $response->json('data.sources.0.tool'));

        $this->getJson('/api/v1/ai/conversations/'.$response->json('data.conversation_id'), $this->headers($workspace))
            ->assertOk()
            ->assertJsonPath('data.messages.0.role', 'user');
    }

    #[Test]
    public function insights_can_be_generated_and_listed(): void
    {
        [$user, $workspace] = $this->world('insightapi@example.test');
        Sanctum::actingAs($user);

        $this->spend(
            $workspace,
            $this->wallet($workspace),
            $this->categoryNamed($workspace, 'restaurant'),
            500_00,
            '2026-07-03 12:00:00',
        );

        $this->postJson('/api/v1/ai/insights/generate', [], $this->headers($workspace))
            ->assertOk()
            ->assertJsonPath('data.0.type', AiInsight::TYPE_COMPOSITION);

        $this->getJson('/api/v1/ai/insights', $this->headers($workspace))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_workspace_with_ai_switched_off_is_refused_at_the_edge(): void
    {
        [$user, $workspace] = $this->world('off@example.test');
        $workspace->forceFill(['settings' => ['ai' => ['enabled' => false]]])->save();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/ai/drafts', [
            'text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
        ], $this->headers($workspace))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ai_disabled');
    }
}
