<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Modules\AI\Actions\ParseTransactionText;
use Modules\AI\Models\AiDraft;
use Modules\AI\Support\TransactionDraft;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The headline feature, and the one with the most ways to be quietly wrong.
 *
 * An amount off by a factor of ten, a date a day out or a rial read as a toman
 * all look like working software right up until someone reconciles their
 * account, so the numeric half of the parse is pinned down here exactly.
 */
final class ParseTransactionTextTest extends AiTestCase
{
    #[Test]
    public function the_worked_example_from_the_specification_parses(): void
    {
        [, $workspace] = $this->world('parse@example.test');

        $draft = $this->parse($workspace, 'دیروز ۳۵۰ لیر برای شام پرداخت کردم');

        $this->assertSame(Transaction::TYPE_EXPENSE, $draft->type);
        $this->assertSame(35000, $draft->amount, '350 lira is 35000 kuruş.');
        $this->assertSame('TRY', $draft->currency);
        $this->assertSame('2026-07-24', $draft->occurredAt->toDateString());
        $this->assertSame('شام', $draft->description);

        $this->assertNotNull($draft->categorySuggestion);
        $this->assertStringEndsWith('/restaurant', (string) $draft->categorySuggestion['path']);

        $this->assertNotNull($draft->accountSuggestion);
        $this->assertSame('TRY', $draft->accountSuggestion['currency']);

        $this->assertGreaterThan(0.85, $draft->confidence);
        $this->assertTrue($draft->needsConfirmation());
        $this->assertFalse($draft->isLowConfidence());
    }

    #[Test]
    public function persian_and_arabic_digits_parse_identically(): void
    {
        [, $workspace] = $this->world('digits@example.test');

        $persian = $this->parse($workspace, 'دیروز ۳۵۰ لیر برای شام پرداخت کردم');
        $arabic = $this->parse($workspace, 'دیروز ٣٥٠ لیر برای شام پرداخت کردم');
        $latin = $this->parse($workspace, 'دیروز 350 لیر برای شام پرداخت کردم');

        $this->assertSame(35000, $persian->amount);
        $this->assertSame($persian->amount, $arabic->amount, 'Arabic-Indic digits must read the same.');
        $this->assertSame($persian->amount, $latin->amount);
        $this->assertSame($persian->occurredAt->toDateString(), $arabic->occurredAt->toDateString());
    }

    #[Test]
    public function toman_is_multiplied_into_rial_by_the_configured_factor(): void
    {
        [, $workspace] = $this->world('toman@example.test', 'IRR');

        $draft = $this->parse($workspace, 'امروز ۳۵۰ هزار تومان خرج کردم');

        // 350 × 1000 toman × 10 rial/toman.
        $this->assertSame(3_500_000, $draft->amount);
        $this->assertSame('IRR', $draft->currency);
        $this->assertContains('toman_converted_at_10', $draft->warnings);
        $this->assertSame(10, $draft->meta['amount_match']['toman_rial_factor']);
    }

    #[Test]
    public function the_toman_factor_is_configurable_per_workspace(): void
    {
        [, $workspace] = $this->world('toman-setting@example.test', 'IRR');

        // A workspace that already keeps its books in toman-as-rial.
        $workspace->forceFill(['settings' => ['ai' => ['toman_rial_factor' => 1]]])->save();

        $draft = $this->parse($workspace->fresh(), 'امروز ۳۵۰ هزار تومان خرج کردم');

        $this->assertSame(350_000, $draft->amount);
        $this->assertContains('toman_converted_at_1', $draft->warnings);
    }

    #[Test]
    public function rial_is_taken_at_face_value(): void
    {
        [, $workspace] = $this->world('rial@example.test', 'IRR');

        $draft = $this->parse($workspace, 'امروز ۳۵۰ هزار ریال خرج کردم');

        $this->assertSame(350_000, $draft->amount);
        $this->assertNotContains('toman_converted_at_10', $draft->warnings);
    }

    #[Test]
    #[DataProvider('relativeDates')]
    public function relative_dates_resolve_against_the_frozen_clock(string $phrase, string $expected): void
    {
        [, $workspace] = $this->world('dates@example.test');

        $draft = $this->parse($workspace, "{$phrase} ۱۰۰ لیر خرج کردم");

        $this->assertSame($expected, $draft->occurredAt->toDateString(), $phrase);
        $this->assertSame(10000, $draft->amount, 'The date must not be read as the amount.');
    }

    /** @return array<string, array{string, string}> */
    public static function relativeDates(): array
    {
        // Frozen at 2026-07-25.
        return [
            'today' => ['امروز', '2026-07-25'],
            'yesterday' => ['دیروز', '2026-07-24'],
            'day before yesterday' => ['پریروز', '2026-07-23'],
            'last week' => ['هفتهٔ پیش', '2026-07-18'],
            'start of month' => ['اول ماه', '2026-07-01'],
            'last month' => ['ماه گذشته', '2026-06-25'],
            'n days ago' => ['۵ روز پیش', '2026-07-20'],
            'english yesterday' => ['yesterday', '2026-07-24'],
            'english last week' => ['last week', '2026-07-18'],
            'english start of month' => ['start of the month', '2026-07-01'],
        ];
    }

    #[Test]
    public function a_jalali_day_and_month_resolves_without_eating_the_amount(): void
    {
        [, $workspace] = $this->world('jalali@example.test');

        $draft = $this->parse($workspace, '۵ شهریور ۳۵۰ لیر برای شام دادم');

        // 5 Shahrivar 1404 — the one already past, not the one still to come.
        $this->assertSame('2025-08-27', $draft->occurredAt->toDateString());
        $this->assertSame(35000, $draft->amount);
    }

    #[Test]
    public function income_wording_is_recognised(): void
    {
        [, $workspace] = $this->world('income@example.test');

        $draft = $this->parse($workspace, 'دیروز ۵۰۰ لیر حقوق گرفتم');

        $this->assertSame(Transaction::TYPE_INCOME, $draft->type);
        $this->assertSame(50000, $draft->amount);
    }

    #[Test]
    public function a_thin_sentence_comes_back_as_a_low_confidence_draft_and_writes_nothing(): void
    {
        [, $workspace] = $this->world('low@example.test');

        $draft = $this->parse($workspace, 'یه چیزی خریدم ۲۰۰');

        $this->assertTrue($draft->isLowConfidence(), 'Assumed currency and date must not read as certainty.');
        $this->assertLessThan(0.7, $draft->confidence);
        $this->assertTrue($draft->needsConfirmation());
        $this->assertContains('currency_assumed', $draft->warnings);
        $this->assertContains('date_assumed', $draft->warnings);
        $this->assertContains('no_category_match', $draft->warnings);
        $this->assertNull($draft->categorySuggestion);

        // The point of the whole module: nothing was recorded.
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => AiDraft::query()->count()));
    }

    #[Test]
    public function parsing_never_writes_a_transaction_even_when_it_is_certain(): void
    {
        [, $workspace] = $this->world('nowrite@example.test');

        $draft = $this->parse($workspace, 'دیروز ۳۵۰ لیر برای شام پرداخت کردم');

        $this->assertGreaterThan(0.85, $draft->confidence);
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    #[Test]
    public function an_english_sentence_parses_too(): void
    {
        [, $workspace] = $this->world('english@example.test');

        $draft = $this->parse($workspace, 'yesterday I paid 12.50 dollars for coffee');

        $this->assertSame(1250, $draft->amount);
        $this->assertSame('USD', $draft->currency);
        $this->assertSame('2026-07-24', $draft->occurredAt->toDateString());
        $this->assertNotNull($draft->categorySuggestion);
        $this->assertStringEndsWith('/restaurant', (string) $draft->categorySuggestion['path']);
    }

    private function parse(Workspace $workspace, string $text): TransactionDraft
    {
        return $this->inWorkspace($workspace, fn () => app(ParseTransactionText::class)->handle($text));
    }
}
