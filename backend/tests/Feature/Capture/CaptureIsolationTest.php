<?php

declare(strict_types=1);

namespace Tests\Feature\Capture;

use Laravel\Sanctum\Sanctum;
use Modules\Capture\Models\CaptureMessage;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Capture opens four new doors into a workspace's books. Every one of them is
 * checked here for the same thing: that a message can only ever reach the
 * workspace it was addressed to.
 *
 * Three of the four are covered by the membership middleware and the global
 * scope. The fourth — email — has neither, because a mail provider has no
 * session; there the recipient address is the entire routing decision, so it
 * gets its own case.
 */
final class CaptureIsolationTest extends CaptureTestCase
{
    #[Test]
    public function a_stranger_cannot_post_to_another_workspace_on_any_channel(): void
    {
        [, $mine] = $this->world('mine@example.test', 'IRR');
        [$stranger] = $this->world('outsider@example.test', 'IRR');

        Sanctum::actingAs($stranger);

        $calls = [
            ['/api/v1/capture/text', ['text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم']],
            ['/api/v1/capture/sms', [
                'sender' => 'BANK',
                'body' => 'مبلغ ۱٬۲۰۰٬۰۰۰ ریال از حساب ۱۲۳۴***۵۶۷۸ برداشت شد',
                'received_at' => '2026-07-25T08:30:00Z',
            ]],
            ['/api/v1/capture/qr', ['payload' => 'amount=125000;currency=IRR;merchant=Cafe']],
        ];

        foreach ($calls as [$uri, $payload]) {
            $this->postJson($uri, $payload, $this->headers($mine))
                ->assertForbidden()
                ->assertJsonPath('error.code', 'workspace_forbidden');
        }

        $this->assertSame(0, $this->inWorkspace($mine, fn () => CaptureMessage::query()->count()));
    }

    #[Test]
    public function another_workspaces_message_is_not_readable(): void
    {
        [$owner, $mine] = $this->world('owner@example.test', 'IRR');
        [$stranger, $theirs] = $this->world('nosy@example.test', 'IRR');

        Sanctum::actingAs($owner);
        $messageId = $this->postJson(
            '/api/v1/capture/text',
            ['text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم'],
            $this->headers($mine),
        )->json('data.id');

        Sanctum::actingAs($stranger);

        // With their own workspace header the id simply does not exist; with
        // mine they are not a member. Neither answer reveals the other.
        $this->getJson("/api/v1/capture/messages/{$messageId}", $this->headers($theirs))->assertNotFound();
        $this->getJson("/api/v1/capture/messages/{$messageId}", $this->headers($mine))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'workspace_forbidden');

        $this->getJson('/api/v1/capture/messages', $this->headers($theirs))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function another_workspaces_message_cannot_be_confirmed(): void
    {
        [$owner, $mine] = $this->world('confirmowner@example.test', 'IRR');
        [$stranger, $theirs] = $this->world('confirmnosy@example.test', 'IRR');

        Sanctum::actingAs($owner);
        $messageId = $this->postJson(
            '/api/v1/capture/text',
            ['text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم'],
            $this->headers($mine),
        )->json('data.id');

        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/capture/messages/{$messageId}/confirm", [], $this->headers($theirs))
            ->assertNotFound();

        $this->assertSame(0, $this->inWorkspace($mine, fn () => Transaction::query()->count()));
        $this->assertNull($this->inWorkspace($mine, fn () => CaptureMessage::query()->sole())->transaction_id);
    }

    #[Test]
    public function mail_lands_in_the_workspace_its_address_belongs_to(): void
    {
        [$owner, $mine] = $this->world('mailmine@example.test', 'IRR');
        [, $theirs] = $this->world('mailtheirs@example.test', 'IRR');

        Sanctum::actingAs($owner);
        $address = (string) $this->postJson('/api/v1/capture/ingest-aliases', [], $this->headers($mine))
            ->assertCreated()
            ->json('data.address');

        app('auth')->forgetGuards();

        $this->postJson('/api/v1/capture/email', [
            'to' => $address,
            'from' => 'receipts@shop.example',
            'subject' => 'Your receipt',
            'text' => 'دیروز ۳۵۰ لیر برای شام پرداخت کردم',
            'received_at' => '2026-07-25T08:00:00Z',
        ], $this->webhookHeaders())->assertCreated();

        $this->assertSame(1, $this->inWorkspace($mine, fn () => CaptureMessage::query()->count()));
        $this->assertSame(0, $this->inWorkspace($theirs, fn () => CaptureMessage::query()->count()));
    }

    #[Test]
    public function an_ingest_alias_of_another_workspace_cannot_be_revoked(): void
    {
        [$owner, $mine] = $this->world('aliasowner@example.test', 'IRR');
        [$stranger, $theirs] = $this->world('aliasnosy@example.test', 'IRR');

        Sanctum::actingAs($owner);
        $aliasId = $this->postJson('/api/v1/capture/ingest-aliases', [], $this->headers($mine))
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($stranger);
        $this->deleteJson("/api/v1/capture/ingest-aliases/{$aliasId}", [], $this->headers($theirs))
            ->assertNotFound();
    }
}
