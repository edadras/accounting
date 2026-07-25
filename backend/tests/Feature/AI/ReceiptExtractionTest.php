<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Modules\AI\Actions\ExtractReceipt;
use Modules\AI\Actions\StoreDraft;
use Modules\AI\Jobs\ExtractReceiptJob;
use Modules\AI\Models\AiDraft;
use Modules\AI\Support\ReceiptDraft;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reading a receipt is only half the job; the other half is noticing when the
 * reading cannot be right.
 */
final class ReceiptExtractionTest extends AiTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_receipt_that_adds_up_is_extracted_field_by_field(): void
    {
        [, $workspace] = $this->world('receipt@example.test', 'IRR');

        $draft = $this->extract($workspace, $this->receipt(total: 82_500));

        $this->assertSame('سوپرمارکت هدف', $draft->fields['merchant']['value']);
        $this->assertSame('IRR', $draft->fields['currency']['value']);
        $this->assertSame(7_500, $draft->fields['tax']['value']);
        $this->assertSame(82_500, $draft->fields['total']['value']);

        $this->assertCount(2, $draft->items);
        $this->assertSame('نان سنگک', $draft->items[0]['name']);
        $this->assertSame(2, $draft->items[0]['quantity']);
        $this->assertSame(15_000, $draft->items[0]['unit_price']);
        $this->assertSame(30_000, $draft->items[0]['line_total']);

        $this->assertTrue($draft->arithmetic['checked']);
        $this->assertTrue($draft->arithmetic['ok']);
        $this->assertSame(75_000, $draft->arithmetic['items_total']);
        $this->assertSame(82_500, $draft->arithmetic['expected_total']);
        $this->assertSame(0, $draft->arithmetic['difference']);
        $this->assertNotContains('arithmetic_mismatch', $draft->warnings);

        // 1405/05/03 is a Jalali date printed on an Iranian receipt.
        $this->assertStringStartsWith('2026-07-25', (string) $draft->fields['occurred_at']['value']);

        $this->assertSame(82_500, $draft->transaction->amount);
        $this->assertTrue($draft->transaction->needsConfirmation());
    }

    #[Test]
    public function a_receipt_whose_total_does_not_match_its_lines_is_flagged_rather_than_trusted(): void
    {
        [, $workspace] = $this->world('mismatch@example.test', 'IRR');

        $draft = $this->extract($workspace, $this->receipt(total: 100_000));

        $this->assertTrue($draft->arithmetic['checked']);
        $this->assertFalse($draft->arithmetic['ok'], 'sum(items) + tax ≠ stated total must not pass silently.');
        $this->assertSame(75_000, $draft->arithmetic['items_total']);
        $this->assertSame(7_500, $draft->arithmetic['tax']);
        $this->assertSame(82_500, $draft->arithmetic['expected_total']);
        $this->assertSame(100_000, $draft->arithmetic['stated_total']);
        $this->assertSame(17_500, $draft->arithmetic['difference']);

        $this->assertContains('arithmetic_mismatch', $draft->warnings);
        $this->assertTrue($draft->transaction->isLowConfidence(), 'A mismatch must drag confidence below the bar.');

        // The stated total still comes through — the user is being warned, not
        // silently overruled — but it arrives marked.
        $this->assertSame(100_000, $draft->transaction->amount);
        $this->assertContains('arithmetic_mismatch', $draft->transaction->warnings);
    }

    #[Test]
    public function a_receipt_with_no_printed_total_falls_back_to_the_sum_at_lower_confidence(): void
    {
        [, $workspace] = $this->world('nototal@example.test', 'IRR');

        $draft = $this->extract($workspace, implode("\n", [
            'سوپرمارکت هدف',
            'تاریخ: 1405/05/03',
            'نان سنگک 2 x 15000',
            'شیر 1 x 45000',
            'مالیات: 7500 ریال',
        ]));

        $this->assertSame(82_500, $draft->transaction->amount);
        $this->assertLessThan(0.7, $draft->confidence);
        $this->assertFalse($draft->arithmetic['ok']);
    }

    #[Test]
    public function an_image_with_no_ocr_engine_yields_an_empty_draft_rather_than_an_invention(): void
    {
        [, $workspace] = $this->world('binary@example.test', 'IRR');

        $path = tempnam(sys_get_temp_dir(), 'receipt').'.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n\0\0\0\0binary");
        $this->files[] = $path;

        $this->expectExceptionMessage('No amount could be read');

        $this->inWorkspace($workspace, fn () => app(ExtractReceipt::class)->handle($path));
    }

    #[Test]
    public function the_queued_job_stores_a_pending_draft_and_records_nothing(): void
    {
        [$user, $workspace] = $this->world('job@example.test', 'IRR');

        $path = $this->write($this->receipt(total: 82_500));

        (new ExtractReceiptJob($workspace->id, $path, null, $user->id))->handle(
            $this->context(),
            app(ExtractReceipt::class),
            app(StoreDraft::class),
        );

        $draft = $this->inWorkspace($workspace, fn () => AiDraft::query()->sole());

        $this->assertSame(AiDraft::KIND_RECEIPT, $draft->kind);
        $this->assertSame(AiDraft::SOURCE_OCR, $draft->source);
        $this->assertSame(AiDraft::STATUS_PENDING, $draft->status);
        $this->assertTrue($draft->needs_confirmation);
        $this->assertNull($draft->transaction_id);
        $this->assertSame(82_500, $draft->payload['transaction']['amount']);

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
    }

    private function receipt(int $total): string
    {
        return implode("\n", [
            'سوپرمارکت هدف',
            'تاریخ: 1405/05/03',
            'نان سنگک 2 x 15000',
            'شیر 1 x 45000',
            'مالیات: 7500 ریال',
            "جمع کل: {$total} ریال",
        ]);
    }

    private function extract(Workspace $workspace, string $contents): ReceiptDraft
    {
        $path = $this->write($contents);

        return $this->inWorkspace($workspace, fn () => app(ExtractReceipt::class)->handle($path));
    }

    private function write(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt');
        $this->assertIsString($path);

        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
