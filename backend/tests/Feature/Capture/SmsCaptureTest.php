<?php

declare(strict_types=1);

namespace Tests\Feature\Capture;

use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiDraft;
use Modules\Capture\Models\CaptureMessage;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Bank SMS capture.
 *
 * The direction word is what the whole channel turns on. «برداشت» and «واریز»
 * differ by one word and by the sign of everything downstream: read backwards,
 * an expense becomes income, which does not merely lose the amount — it moves
 * it to the other side of every total and shows up at twice its size. So every
 * configured shape is exercised in both directions, and the assertion is on the
 * transaction type, not on the amount alone.
 */
final class SmsCaptureTest extends CaptureTestCase
{
    private const SENDER = 'BANK';

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: int, 5: string, 6: int|null}>
     */
    public static function bankShapes(): array
    {
        return [
            'ir_account_movement · withdrawal' => [
                'ir_account_movement',
                'IRR',
                'مبلغ ۱٬۲۰۰٬۰۰۰ ریال از حساب ۱۲۳۴***۵۶۷۸ برداشت شد. مانده ۵٬۰۰۰٬۰۰۰ ریال',
                Transaction::TYPE_EXPENSE,
                1_200_000,
                'IRR',
                5_000_000,
            ],
            'ir_account_movement · deposit' => [
                'ir_account_movement',
                'IRR',
                'مبلغ ۵۰۰٬۰۰۰ ریال به حساب ۱۲۳۴***۵۶۷۸ واریز شد. مانده ۲٬۵۰۰٬۰۰۰ ریال',
                Transaction::TYPE_INCOME,
                500_000,
                'IRR',
                2_500_000,
            ],
            'ir_card_purchase · withdrawal' => [
                'ir_card_purchase',
                'IRR',
                'خرید اینترنتی مبلغ ۲۵۰٬۰۰۰ ریال کارت ۶۰۳۷***۱۲۳۴ مانده ۳٬۰۰۰٬۰۰۰',
                Transaction::TYPE_EXPENSE,
                250_000,
                'IRR',
                3_000_000,
            ],
            'ir_card_purchase · deposit' => [
                'ir_card_purchase',
                'IRR',
                'بازگشت وجه مبلغ ۲۵۰٬۰۰۰ ریال کارت ۶۰۳۷***۱۲۳۴ مانده ۳٬۲۵۰٬۰۰۰',
                Transaction::TYPE_INCOME,
                250_000,
                'IRR',
                3_250_000,
            ],
            'ir_account_first · deposit' => [
                'ir_account_first',
                'IRR',
                'حساب ۱۲۳۴***۵۶۷۸ واریز ۵۰۰٬۰۰۰ ریال مانده ۲٬۵۰۰٬۰۰۰ ریال',
                Transaction::TYPE_INCOME,
                500_000,
                'IRR',
                2_500_000,
            ],
            'ir_account_first · withdrawal' => [
                'ir_account_first',
                'IRR',
                'حساب ۱۲۳۴***۵۶۷۸ برداشت ۵۰۰٬۰۰۰ ریال مانده ۲٬۰۰۰٬۰۰۰ ریال',
                Transaction::TYPE_EXPENSE,
                500_000,
                'IRR',
                2_000_000,
            ],
            'en_account_debit_credit · withdrawal' => [
                'en_account_debit_credit',
                'USD',
                'Your account ****1234 has been debited USD 45.20. Available balance USD 300.00',
                Transaction::TYPE_EXPENSE,
                4_520,
                'USD',
                30_000,
            ],
            'en_account_debit_credit · deposit' => [
                'en_account_debit_credit',
                'USD',
                'Your account ****1234 has been credited USD 45.20. Available balance USD 390.40',
                Transaction::TYPE_INCOME,
                4_520,
                'USD',
                39_040,
            ],
            'tr_account_movement · withdrawal' => [
                'tr_account_movement',
                'TRY',
                '1234 nolu hesabınızdan 250,00 TL çekilmiştir. Bakiye: 1.000,00 TL',
                Transaction::TYPE_EXPENSE,
                25_000,
                'TRY',
                100_000,
            ],
            'tr_account_movement · deposit' => [
                'tr_account_movement',
                'TRY',
                '1234 nolu hesabınıza 250,00 TL yatırılmıştır. Bakiye: 1.250,00 TL',
                Transaction::TYPE_INCOME,
                25_000,
                'TRY',
                125_000,
            ],
        ];
    }

    #[Test]
    #[DataProvider('bankShapes')]
    public function every_configured_shape_parses_in_both_directions(
        string $pattern,
        string $workspaceCurrency,
        string $body,
        string $expectedType,
        int $expectedAmount,
        string $expectedCurrency,
        ?int $expectedBalance,
    ): void {
        [$user, $workspace] = $this->world('sms-'.md5($body).'@example.test', $workspaceCurrency);
        Sanctum::actingAs($user);

        $response = $this->deliver($workspace, $body);

        $response->assertCreated();
        $response->assertJsonPath('data.status', CaptureMessage::STATUS_PARSED);
        $response->assertJsonPath('data.matched_pattern', $pattern);
        $response->assertJsonPath('data.draft.draft.type', $expectedType);
        $response->assertJsonPath('data.draft.draft.amount', $expectedAmount);
        $response->assertJsonPath('data.draft.draft.currency', $expectedCurrency);
        $response->assertJsonPath('data.parsed.balance', $expectedBalance);

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function persian_and_arabic_digits_both_parse(): void
    {
        [$user, $workspace] = $this->world('digits@example.test', 'IRR');
        Sanctum::actingAs($user);

        // Eastern Arabic-Indic digits, as an Arabic keyboard produces them.
        $this->deliver($workspace, 'مبلغ ١٬٢٠٠٬٠٠٠ ریال از حساب ١٢٣٤***٥٦٧٨ برداشت شد. مانده ٥٬٠٠٠٬٠٠٠ ریال')
            ->assertCreated()
            ->assertJsonPath('data.status', CaptureMessage::STATUS_PARSED)
            ->assertJsonPath('data.draft.draft.amount', 1_200_000)
            ->assertJsonPath('data.parsed.balance', 5_000_000);

        // Persian digits, one minute later so this is not read as a redelivery.
        $this->deliver(
            $workspace,
            'مبلغ ۱٬۲۰۰٬۰۰۰ ریال از حساب ۱۲۳۴***۵۶۷۸ برداشت شد. مانده ۵٬۰۰۰٬۰۰۰ ریال',
            '2026-07-25T08:31:00Z',
        )
            ->assertCreated()
            ->assertJsonPath('data.status', CaptureMessage::STATUS_PARSED)
            ->assertJsonPath('data.draft.draft.amount', 1_200_000);
    }

    #[Test]
    public function toman_is_converted_and_the_factor_is_reported(): void
    {
        [$user, $workspace] = $this->world('toman@example.test', 'IRR');
        $this->card($workspace, '5678');
        Sanctum::actingAs($user);

        $this->deliver($workspace, 'مبلغ ۱۲۰٬۰۰۰ تومان از حساب ۱۲۳۴***۵۶۷۸ برداشت شد')
            ->assertCreated()
            ->assertJsonPath('data.draft.draft.amount', 1_200_000)
            ->assertJsonPath('data.draft.draft.currency', 'IRR')
            ->assertJsonPath('data.draft.draft.warnings', ['toman_converted_at_10']);
    }

    #[Test]
    public function the_masked_fragment_is_matched_to_an_account_when_one_fits(): void
    {
        [$user, $workspace] = $this->world('card@example.test', 'IRR');
        $card = $this->card($workspace, '1234');
        Sanctum::actingAs($user);

        $this->deliver($workspace, 'خرید اینترنتی مبلغ ۲۵۰٬۰۰۰ ریال کارت ۶۰۳۷***۱۲۳۴ مانده ۳٬۰۰۰٬۰۰۰')
            ->assertCreated()
            ->assertJsonPath('data.parsed.account_match', 'matched_last4')
            ->assertJsonPath('data.draft.draft.account_suggestion.id', $card->id);
    }

    #[Test]
    public function an_unmatched_fragment_is_left_for_the_user_rather_than_attached_to_the_nearest_account(): void
    {
        [$user, $workspace] = $this->world('nocard@example.test', 'IRR');
        Sanctum::actingAs($user);

        $this->deliver($workspace, 'خرید اینترنتی مبلغ ۲۵۰٬۰۰۰ ریال کارت ۶۰۳۷***۹۹۹۹ مانده ۳٬۰۰۰٬۰۰۰')
            ->assertCreated()
            ->assertJsonPath('data.parsed.account_match', 'account_unmatched')
            ->assertJsonPath('data.draft.draft.account_suggestion', null)
            ->assertJsonPath('data.draft.warnings', ['account_unmatched']);
    }

    #[Test]
    public function the_same_sms_delivered_twice_produces_one_draft(): void
    {
        [$user, $workspace] = $this->world('dedupe@example.test', 'IRR');
        Sanctum::actingAs($user);

        $body = 'مبلغ ۱٬۲۰۰٬۰۰۰ ریال از حساب ۱۲۳۴***۵۶۷۸ برداشت شد. مانده ۵٬۰۰۰٬۰۰۰ ریال';

        $first = $this->deliver($workspace, $body)->assertCreated();
        $second = $this->deliver($workspace, $body)->assertCreated();

        $second->assertJsonPath('data.status', CaptureMessage::STATUS_DUPLICATE);
        $second->assertJsonPath('data.duplicate_of_id', $first->json('data.id'));

        // The redelivery is still recorded — it just points at the draft the
        // first one made instead of making a second.
        $this->assertSame($first->json('data.draft.id'), $second->json('data.draft.id'));
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => AiDraft::query()->count()));
        $this->assertSame(2, $this->inWorkspace($workspace, fn () => CaptureMessage::query()->count()));
    }

    #[Test]
    public function an_unrecognised_sms_is_stored_unparsed_and_creates_no_draft(): void
    {
        [$user, $workspace] = $this->world('unknown@example.test', 'IRR');
        Sanctum::actingAs($user);

        $this->deliver($workspace, 'سلام، یادت نره فردا زنگ بزنی.')
            ->assertCreated()
            ->assertJsonPath('data.status', CaptureMessage::STATUS_UNPARSED)
            ->assertJsonPath('data.reason', 'no_pattern_matched')
            ->assertJsonPath('data.draft', null);

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => AiDraft::query()->count()));
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => CaptureMessage::query()->count()));
    }

    #[Test]
    public function a_shape_that_matches_but_uses_an_unknown_direction_word_is_not_guessed_at(): void
    {
        [$user, $workspace] = $this->world('unknowndirection@example.test', 'IRR');
        Sanctum::actingAs($user);

        // «مسدود» — blocked — is neither in nor out. Booking it either way
        // would be a coin flip on the sign of the entry.
        config(['sms.patterns.blocked_shape' => [
            'bank' => 'ir_generic',
            'label' => 'test shape with an unlisted direction word',
            'senders' => [],
            'default_currency' => 'IRR',
            'number_format' => 'plain',
            'pattern' => '/مبلغ\s*(?P<amount>\d+)\s*(?P<currency>ریال)\s*(?P<direction>مسدود)/u',
        ]]);

        $this->deliver($workspace, 'مبلغ ۹۹۰۰۰ ریال مسدود گردید')
            ->assertCreated()
            ->assertJsonPath('data.status', CaptureMessage::STATUS_UNPARSED);
    }

    #[Test]
    public function adding_a_bank_is_a_config_entry(): void
    {
        [$user, $workspace] = $this->world('newbank@example.test', 'IRR');
        Sanctum::actingAs($user);

        config(['sms.patterns.bank_zenith' => [
            'bank' => 'zenith',
            'label' => 'Zenith Bank',
            'senders' => ['zenith'],
            'default_currency' => 'IRR',
            'number_format' => 'plain',
            'pattern' => '/zenith\s*(?P<direction>واریز|برداشت)\s*(?P<amount>\d(?:[\d.,]*\d)?)\s*(?P<currency>ریال)/u',
        ]]);

        $body = 'zenith واریز ۷۵۰۰۰۰ ریال';

        // The sender gate is part of the entry: the same text from another
        // sender is not this bank's message.
        $this->deliver($workspace, $body, sender: 'OTHER')
            ->assertCreated()
            ->assertJsonPath('data.status', CaptureMessage::STATUS_UNPARSED);

        $this->deliver($workspace, $body, sender: 'ZENITH', receivedAt: '2026-07-25T08:35:00Z')
            ->assertCreated()
            ->assertJsonPath('data.matched_pattern', 'bank_zenith')
            ->assertJsonPath('data.draft.draft.type', Transaction::TYPE_INCOME)
            ->assertJsonPath('data.draft.draft.amount', 750_000);
    }

    #[Test]
    public function confirming_a_captured_sms_records_it_as_an_sms(): void
    {
        [$user, $workspace] = $this->world('smsconfirm@example.test', 'IRR');
        $card = $this->card($workspace, '5678');
        Sanctum::actingAs($user);

        $messageId = $this->deliver($workspace, 'مبلغ ۱٬۲۰۰٬۰۰۰ ریال از حساب ۱۲۳۴***۵۶۷۸ برداشت شد')
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/capture/messages/{$messageId}/confirm", [], $this->headers($workspace))
            ->assertCreated();

        $transaction = $this->inWorkspace($workspace, fn () => Transaction::query()->sole());

        $this->assertSame(Transaction::TYPE_EXPENSE, $transaction->type);
        $this->assertSame(1_200_000, $transaction->amount);
        $this->assertSame($card->id, $transaction->account_id);
        // transactions.source names sms; a payment read off a bank message
        // should say so rather than claim to have been typed in by hand.
        $this->assertSame('sms', $transaction->source);
        $this->assertSame($messageId, $transaction->source_meta['capture_message_id']);
    }

    private function deliver(
        Workspace $workspace,
        string $body,
        string $receivedAt = '2026-07-25T08:30:00Z',
        string $sender = self::SENDER,
    ): TestResponse {
        return $this->postJson('/api/v1/capture/sms', [
            'sender' => $sender,
            'body' => $body,
            'received_at' => $receivedAt,
        ], $this->headers($workspace));
    }
}
