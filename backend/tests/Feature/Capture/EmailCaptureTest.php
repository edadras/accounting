<?php

declare(strict_types=1);

namespace Tests\Feature\Capture;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiDraft;
use Modules\Capture\Models\CaptureMessage;
use Modules\Capture\Models\IngestAlias;
use Modules\Core\Models\Workspace;
use Modules\Documents\Models\Document;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Inbound email capture.
 *
 * Two things stand between "a receipt arrived in my books" and "anyone on the
 * internet can write into a stranger's ledger": the shared secret on the
 * webhook, and the fact that the recipient address — not anything in the
 * message — decides whose books it lands in. Both are tested here, because
 * neither has a user session to fall back on.
 */
final class EmailCaptureTest extends CaptureTestCase
{
    private const RECEIPT = "سوپرمارکت هدف\nتاریخ: 1405/05/03\nنان سنگک 2 x 15000\nشیر 1 x 45000\nمالیات: 7500 ریال\nجمع کل: 82500 ریال";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    #[Test]
    public function the_webhook_refuses_a_missing_secret(): void
    {
        [$user, $workspace] = $this->world('nosecret@example.test', 'IRR');
        $address = $this->address($user, $workspace);

        $this->postJson('/api/v1/capture/email', $this->mail($address), ['Accept' => 'application/json'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'capture_webhook_unauthorized');

        $this->assertNothingCaptured($workspace);
    }

    #[Test]
    public function the_webhook_refuses_a_wrong_secret(): void
    {
        [$user, $workspace] = $this->world('wrongsecret@example.test', 'IRR');
        $address = $this->address($user, $workspace);

        $this->postJson('/api/v1/capture/email', $this->mail($address), $this->webhookHeaders('not-the-secret'))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'capture_webhook_unauthorized');

        $this->assertNothingCaptured($workspace);
    }

    #[Test]
    public function an_unconfigured_deployment_refuses_every_delivery(): void
    {
        [$user, $workspace] = $this->world('unconfigured@example.test', 'IRR');
        $address = $this->address($user, $workspace);

        config(['capture.webhook.secret' => null]);

        $this->postJson('/api/v1/capture/email', $this->mail($address), $this->webhookHeaders())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'capture_webhook_not_configured');
    }

    #[Test]
    public function a_signed_delivery_is_accepted(): void
    {
        [$user, $workspace] = $this->world('signed@example.test', 'IRR');
        $address = $this->address($user, $workspace);

        $payload = $this->mail($address);
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->call(
            'POST',
            '/api/v1/capture/email',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CAPTURE_SIGNATURE' => 'sha256='.hash_hmac('sha256', (string) $body, self::WEBHOOK_SECRET),
            ],
            content: (string) $body,
        )->assertCreated();
    }

    #[Test]
    public function mail_to_an_address_no_workspace_owns_is_refused(): void
    {
        $this->world('stranger@example.test', 'IRR');

        $this->postJson(
            '/api/v1/capture/email',
            $this->mail('deadbeefdeadbeefdeadbeefdeadbeef@inbox.finora.app'),
            $this->webhookHeaders(),
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'capture_unknown_recipient');
    }

    #[Test]
    public function a_revoked_alias_stops_accepting_mail(): void
    {
        [$user, $workspace] = $this->world('revoked@example.test', 'IRR');
        $address = $this->address($user, $workspace);

        $alias = $this->inWorkspace($workspace, fn () => IngestAlias::query()->sole());
        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/capture/ingest-aliases/{$alias->id}", [], $this->headers($workspace))
            ->assertNoContent();

        $this->postJson('/api/v1/capture/email', $this->mail($address), $this->webhookHeaders())
            ->assertStatus(404);
    }

    #[Test]
    public function an_attachment_becomes_a_document_and_feeds_the_receipt_pipeline(): void
    {
        [$user, $workspace] = $this->world('receipt@example.test', 'IRR');
        $address = $this->address($user, $workspace);

        $this->postJson('/api/v1/capture/email', $this->mail($address, attachments: [[
            'filename' => 'receipt.txt',
            'content_type' => 'text/plain',
            'content' => base64_encode(self::RECEIPT),
        ]]), $this->webhookHeaders())
            ->assertCreated()
            ->assertJsonPath('data.status', CaptureMessage::STATUS_PARSED)
            ->assertJsonPath('data.reason', 'receipt_queued');

        $document = $this->inWorkspace($workspace, fn () => Document::query()->sole());

        $this->assertSame('receipt.txt', $document->original_name);
        $this->assertSame(Document::KIND_RECEIPT, $document->kind);
        $this->assertSame(strlen(self::RECEIPT), $document->size);
        Storage::disk('local')->assertExists((string) $document->path);

        $this->inWorkspace($workspace, function () use ($document): void {
            $message = CaptureMessage::query()->sole();

            $this->assertSame(
                1,
                $document->documentables()->where('documentable_id', $message->id)->count(),
                'The attachment must hang off the message it arrived on.',
            );
        });

        // The queue runs inline under test, so the receipt pipeline has already
        // produced its draft — and only a draft.
        $draft = $this->inWorkspace($workspace, fn () => AiDraft::query()->sole());
        $this->assertSame(AiDraft::KIND_RECEIPT, $draft->kind);
        $this->assertSame(AiDraft::STATUS_PENDING, $draft->status);
        $this->assertSame(82_500, $draft->payload['transaction']['amount']);
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function an_attachment_that_is_not_a_receipt_is_filed_and_not_read(): void
    {
        [$user, $workspace] = $this->world('archive@example.test', 'IRR');
        $address = $this->address($user, $workspace);

        $this->postJson('/api/v1/capture/email', $this->mail($address, text: null, attachments: [[
            'filename' => 'statements.zip',
            'content_type' => 'application/zip',
            'content' => base64_encode("PK\x03\x04\x00\x00binary junk"),
        ]]), $this->webhookHeaders())
            ->assertCreated()
            ->assertJsonPath('data.status', CaptureMessage::STATUS_UNPARSED);

        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Document::query()->count()));
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => AiDraft::query()->count()));
    }

    #[Test]
    public function a_body_with_an_amount_in_it_becomes_a_draft(): void
    {
        [$user, $workspace] = $this->world('body@example.test');
        $address = $this->address($user, $workspace);

        $this->postJson(
            '/api/v1/capture/email',
            $this->mail($address, text: 'دیروز ۳۵۰ لیر برای شام پرداخت کردم'),
            $this->webhookHeaders(),
        )
            ->assertCreated()
            ->assertJsonPath('data.status', CaptureMessage::STATUS_PARSED)
            ->assertJsonPath('data.reason', 'body_parsed')
            ->assertJsonPath('data.draft.draft.amount', 35000);

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function the_same_mail_delivered_twice_produces_one_draft(): void
    {
        [$user, $workspace] = $this->world('maildupe@example.test');
        $address = $this->address($user, $workspace);

        $payload = $this->mail($address, text: 'دیروز ۳۵۰ لیر برای شام پرداخت کردم');

        $first = $this->postJson('/api/v1/capture/email', $payload, $this->webhookHeaders())->assertCreated();
        $second = $this->postJson('/api/v1/capture/email', $payload, $this->webhookHeaders())->assertCreated();

        $second->assertJsonPath('data.status', CaptureMessage::STATUS_DUPLICATE);
        $second->assertJsonPath('data.duplicate_of_id', $first->json('data.id'));

        $this->assertSame(1, $this->inWorkspace($workspace, fn () => AiDraft::query()->count()));
    }

    #[Test]
    public function the_ingest_address_is_returned_once_and_never_again(): void
    {
        [$user, $workspace] = $this->world('alias@example.test');
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/capture/ingest-aliases', ['label' => 'receipts'], $this->headers($workspace))
            ->assertCreated();

        $address = (string) $created->json('data.address');
        $this->assertStringContainsString('@inbox.finora.app', $address);

        $listed = $this->getJson('/api/v1/capture/ingest-aliases', $this->headers($workspace))->assertOk();
        $this->assertNull($listed->json('data.0.address'));

        // Only the hash is stored, so the address cannot be read back out.
        $alias = $this->inWorkspace($workspace, fn () => IngestAlias::query()->sole());
        $this->assertSame(IngestAlias::hash(explode('@', $address)[0]), $alias->token_hash);
    }

    /**
     * @param  list<array<string, string>>  $attachments
     * @return array<string, mixed>
     */
    private function mail(
        string $to,
        ?string $text = 'Thanks for your purchase.',
        array $attachments = [],
    ): array {
        return array_filter([
            'to' => $to,
            'from' => 'receipts@shop.example',
            'subject' => 'Your receipt',
            'text' => $text,
            'message_id' => '<msg-'.md5($to.(string) $text).'@shop.example>',
            'received_at' => '2026-07-25T08:00:00Z',
            'attachments' => $attachments,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function address(User $user, Workspace $workspace): string
    {
        Sanctum::actingAs($user);

        $address = (string) $this->postJson('/api/v1/capture/ingest-aliases', [], $this->headers($workspace))
            ->assertCreated()
            ->json('data.address');

        // The webhook has no session; leaving one behind would hide a missing
        // authentication check rather than test one.
        app('auth')->forgetGuards();

        return $address;
    }

    private function assertNothingCaptured(Workspace $workspace): void
    {
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => CaptureMessage::query()->count()));
    }
}
