<?php

declare(strict_types=1);

namespace Tests\Feature\Capture;

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiDraft;
use Modules\Capture\Models\CaptureMessage;
use Modules\Capture\Support\ParsedQr;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * QR capture: two payload shapes, and a refusal for everything else.
 *
 * The refusal is the interesting half. A QR is scanned in a shop, in a hurry,
 * and a payload read as something it is not produces a draft that looks exactly
 * as trustworthy as a correct one.
 */
final class QrCaptureTest extends CaptureTestCase
{
    /** A real EMVCo merchant-presented payload, CRC and all. */
    private const EMV = '00020101021153039495406125.505802TR5909KAHVE EVI6008ISTANBUL62070503ABC63043048';

    #[Test]
    public function a_key_value_receipt_payload_becomes_a_draft(): void
    {
        [$user, $workspace] = $this->world('qrkv@example.test', 'IRR');
        Sanctum::actingAs($user);

        $response = $this->scan($workspace, 'amount=125000;currency=IRR;merchant=Cafe Naderi;date=2026-07-20;ref=INV-42');

        $response->assertCreated();
        $response->assertJsonPath('data.status', CaptureMessage::STATUS_PARSED);
        $response->assertJsonPath('data.matched_pattern', ParsedQr::FORMAT_RECEIPT_KV);
        $response->assertJsonPath('data.draft.draft.amount', 125_000);
        $response->assertJsonPath('data.draft.draft.currency', 'IRR');
        $response->assertJsonPath('data.draft.draft.type', Transaction::TYPE_EXPENSE);
        $response->assertJsonPath('data.draft.draft.description', 'Cafe Naderi');
        $response->assertJsonPath('data.draft.draft.occurred_at', '2026-07-20T00:00:00+00:00');

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function persian_digits_in_a_receipt_payload_parse(): void
    {
        [$user, $workspace] = $this->world('qrfa@example.test', 'IRR');
        Sanctum::actingAs($user);

        $this->scan($workspace, 'amount=۱۲۵۰۰۰;currency=IRR;merchant=کافه نادری')
            ->assertCreated()
            ->assertJsonPath('data.draft.draft.amount', 125_000);
    }

    #[Test]
    public function an_emv_payment_payload_becomes_a_draft(): void
    {
        [$user, $workspace] = $this->world('qremv@example.test');
        Sanctum::actingAs($user);

        $response = $this->scan($workspace, self::EMV);

        $response->assertCreated();
        $response->assertJsonPath('data.matched_pattern', ParsedQr::FORMAT_EMV_TLV);
        $response->assertJsonPath('data.draft.draft.amount', 12_550);
        $response->assertJsonPath('data.draft.draft.currency', 'TRY');
        $response->assertJsonPath('data.draft.draft.description', 'KAHVE EVI');
        $response->assertJsonPath('data.parsed.reference', 'ABC');
    }

    #[Test]
    public function a_malformed_payload_is_rejected_rather_than_guessed_at(): void
    {
        [$user, $workspace] = $this->world('qrbad@example.test');
        Sanctum::actingAs($user);

        $this->scan($workspace, 'scanned something but it is just a sentence')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'capture_qr_unrecognised');

        // Refused, but not forgotten: the payload is kept so a format worth
        // supporting can be found instead of guessed at now.
        $message = $this->inWorkspace($workspace, fn () => CaptureMessage::query()->sole());
        $this->assertSame(CaptureMessage::STATUS_REJECTED, $message->status);
        $this->assertSame('capture_qr_unrecognised', $message->reason);
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => AiDraft::query()->count()));
    }

    #[Test]
    public function a_payload_that_fails_its_own_checksum_is_rejected(): void
    {
        [$user, $workspace] = $this->world('qrcrc@example.test');
        Sanctum::actingAs($user);

        // Everything but the last four characters is a valid payload; those
        // four are the CRC the issuer computed over the rest.
        $this->scan($workspace, substr(self::EMV, 0, -4).'0000')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'capture_qr_checksum_mismatch');
    }

    #[Test]
    public function an_emv_payload_with_no_amount_is_refused_rather_than_drafted_at_zero(): void
    {
        [$user, $workspace] = $this->world('qrnoamount@example.test');
        Sanctum::actingAs($user);

        $this->scan($workspace, '0002010102115303949'.'5802TR'.'5909KAHVE EVI')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'capture_qr_unrecognised')
            ->assertJsonPath('error.details.reason', 'emv_without_amount');
    }

    #[Test]
    public function a_key_value_payload_with_nothing_but_an_amount_is_not_treated_as_a_receipt(): void
    {
        [$user, $workspace] = $this->world('qrthin@example.test');
        Sanctum::actingAs($user);

        $this->scan($workspace, 'amount=125000')
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'not_a_receipt_payload');
    }

    #[Test]
    public function the_same_code_scanned_twice_produces_one_draft(): void
    {
        [$user, $workspace] = $this->world('qrdupe@example.test');
        Sanctum::actingAs($user);

        $first = $this->scan($workspace, self::EMV)->assertCreated();
        $second = $this->scan($workspace, self::EMV)->assertCreated();

        $second->assertJsonPath('data.status', CaptureMessage::STATUS_DUPLICATE);
        $second->assertJsonPath('data.duplicate_of_id', $first->json('data.id'));

        $this->assertSame(1, $this->inWorkspace($workspace, fn () => AiDraft::query()->count()));
    }

    /**
     * @return TestResponse<Response>
     */
    private function scan(Workspace $workspace, string $payload): TestResponse
    {
        return $this->postJson('/api/v1/capture/qr', ['payload' => $payload], $this->headers($workspace));
    }
}
