<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Category;
use Modules\Reports\Models\ReportExport;
use Modules\Reports\Providers\ReportsServiceProvider;
use Modules\Reports\Support\ExportLabels;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * An export is the report leaving the product. Everything that was true inside
 * it has to still be true in the file: the amount, the calendar, the workspace
 * it belongs to. A spreadsheet that says 35000 where the app said ₺350.00 is
 * worse than no export at all, because the user will act on it.
 */
final class ReportExportTest extends LedgerTestCase
{
    use RefreshDatabase;

    /** @see ReportsTest::createApplication() — same reason. */
    public function createApplication()
    {
        $app = parent::createApplication();
        $app->register(ReportsServiceProvider::class);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('report_exports')) {
            $this->artisan('migrate', ['--path' => 'modules/Reports/Database/Migrations']);
        }

        ExportLabels::flush();

        config(['reports.exports.disk' => 'local']);

        Storage::fake('local');
    }

    #[Test]
    public function a_csv_carries_the_decimal_amount_and_never_the_minor_units(): void
    {
        [$user, $workspace] = $this->booksWithOneExpense();

        Sanctum::actingAs($user);

        $response = $this->post(
            '/api/v1/reports/cash-flow/export',
            ['format' => 'csv', 'locale' => 'en', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $this->headers($workspace),
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->getContent();

        // ₺350.00 was spent. The file says so in major units.
        $this->assertStringContainsString('350.00', $csv);

        // And nowhere does it say 35000 — the integer the ledger stores. Read
        // as a number by a spreadsheet that would be a hundred times the truth.
        $this->assertStringNotContainsString('35000', $csv);

        // No thousands separator: it is the field separator in half the world.
        $this->assertStringNotContainsString('"350.00"', $csv);
        $this->assertStringNotContainsString('350,00', $csv);

        $rows = $this->parseCsv($csv);

        $this->assertSame(
            ['Period', 'From', 'To', 'Income', 'Expense', 'Net', 'Transactions'],
            $rows[0],
        );
        $this->assertSame('2026-03', $rows[1][0]);
        $this->assertSame('350.00', $rows[1][4]);
        $this->assertSame('-350.00', $rows[1][5]);
    }

    #[Test]
    public function a_report_with_no_rows_exports_headers_rather_than_an_error(): void
    {
        $user = $this->makeUser('empty-export@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        Sanctum::actingAs($user);

        // An inverted range is empty by contract, so the report has no periods
        // at all — not even a zero-filled one.
        $response = $this->post(
            '/api/v1/reports/top-merchants/export',
            ['format' => 'csv', 'locale' => 'en', 'from' => '2026-03-31', 'to' => '2026-03-01'],
            $this->headers($workspace),
        );

        $response->assertOk();

        $rows = $this->parseCsv($response->getContent());

        $this->assertSame(['Rank', 'Merchant', 'Total', 'Transactions', 'Share %'], $rows[0]);
        $this->assertCount(1, $rows);

        $export = $this->inWorkspace($workspace, fn () => ReportExport::query()->sole());

        $this->assertSame(ReportExport::STATUS_READY, $export->status);
        $this->assertSame(0, $export->row_count);

        // The other two formats answer an empty report the same way: a file
        // with its headings and nothing under them, never an error.
        $xlsx = $this->post(
            '/api/v1/reports/top-merchants/export',
            ['format' => 'xlsx', 'locale' => 'en', 'from' => '2026-03-31', 'to' => '2026-03-01'],
            $this->headers($workspace),
        );

        $xlsx->assertOk();

        $file = tempnam(sys_get_temp_dir(), 'empty').'.xlsx';
        file_put_contents($file, $xlsx->getContent());

        try {
            $sheet = IOFactory::load($file)->getActiveSheet();

            $this->assertSame('Rank', $sheet->getCell('A1')->getValue());
            $this->assertSame(1, $sheet->getHighestDataRow());
        } finally {
            @unlink($file);
        }

        $this->post(
            '/api/v1/reports/top-merchants/export',
            ['format' => 'pdf', 'locale' => 'fa', 'from' => '2026-03-31', 'to' => '2026-03-01'],
            $this->headers($workspace),
        )->assertOk();
    }

    #[Test]
    public function an_xlsx_amount_is_a_number_a_spreadsheet_can_sum(): void
    {
        [$user, $workspace] = $this->booksWithOneExpense();

        Sanctum::actingAs($user);

        $response = $this->post(
            '/api/v1/reports/cash-flow/export',
            ['format' => 'xlsx', 'locale' => 'en', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $this->headers($workspace),
        );

        $response->assertOk();

        $file = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        file_put_contents($file, $response->getContent());

        $sheet = IOFactory::load($file)->getActiveSheet();

        try {
            $this->assertSame('Expense', $sheet->getCell('E1')->getValue());

            $cell = $sheet->getCell('E2');

            // A right-aligned string that looks like money returns 0 from SUM().
            $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
            $this->assertEqualsWithDelta(350.0, (float) $cell->getValue(), 0.0001);

            // The currency lives in the number format, so the cell stays a number.
            $this->assertStringContainsString('₺', $cell->getStyle()->getNumberFormat()->getFormatCode());
            $this->assertStringContainsString('0.00', $cell->getStyle()->getNumberFormat()->getFormatCode());

            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function a_pdf_is_a_pdf(): void
    {
        [$user, $workspace] = $this->booksWithOneExpense();

        Sanctum::actingAs($user);

        $response = $this->post(
            '/api/v1/reports/cash-flow/export',
            ['format' => 'pdf', 'locale' => 'en', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $this->headers($workspace),
        );

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));

        $export = $this->inWorkspace($workspace, fn () => ReportExport::query()->sole());

        Storage::disk('local')->assertExists($export->path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($export->path));
    }

    #[Test]
    public function a_persian_pdf_embeds_a_font_that_has_persian_glyphs(): void
    {
        [$user, $workspace] = $this->booksWithOneExpense();

        Sanctum::actingAs($user);

        $response = $this->post(
            '/api/v1/reports/cash-flow/export',
            ['format' => 'pdf', 'locale' => 'fa', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $this->headers($workspace),
        );

        $response->assertOk();

        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF', $pdf);

        // Not the built-in Latin font: a PDF whose only font is DejaVu Sans is
        // a PDF full of empty boxes where the Persian was.
        $this->assertMatchesRegularExpression('/\/BaseFont\s*\/[A-Z]{6}\+Vazirmatn/', $pdf);
    }

    #[Test]
    public function an_unknown_format_or_report_type_is_refused(): void
    {
        $user = $this->makeUser('whitelist-export@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');

        Sanctum::actingAs($user);

        // A format that is not on the list, including one shaped like a class.
        foreach (['docx', 'Modules\Reports\Exporters\CsvExporter', '../../etc/passwd'] as $format) {
            $this->postJson(
                '/api/v1/reports/cash-flow/export',
                ['format' => $format],
                $this->headers($workspace),
            )->assertStatus(422)->assertJsonValidationErrors('format');
        }

        // A report type that is not on the list, likewise.
        $this->postJson(
            '/api/v1/reports/Modules%5CReports%5CQueries%5CCashFlowReport/export',
            ['format' => 'csv'],
            $this->headers($workspace),
        )->assertNotFound()->assertJsonPath('error.code', 'unknown_report');

        $this->postJson(
            '/api/v1/reports/cash-flow/export',
            ['format' => 'csv', 'locale' => 'de'],
            $this->headers($workspace),
        )->assertStatus(422)->assertJsonValidationErrors('locale');

        // Nothing was created for any of them.
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => ReportExport::query()->count()));
    }

    #[Test]
    public function another_workspaces_export_cannot_be_read_or_downloaded(): void
    {
        [$owner, $workspace] = $this->booksWithOneExpense();

        Sanctum::actingAs($owner);

        $created = $this->post(
            '/api/v1/reports/cash-flow/export',
            ['format' => 'csv', 'locale' => 'en', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $this->headers($workspace),
        );

        $created->assertOk();
        $exportId = $created->headers->get('X-Report-Export-Id');
        $this->assertNotNull($exportId);

        // The owner can fetch it back from its id.
        $this->getJson("/api/v1/reports/exports/{$exportId}", $this->headers($workspace))
            ->assertOk()
            ->assertJsonPath('data.status', ReportExport::STATUS_READY);

        $this->get("/api/v1/reports/exports/{$exportId}/download", $this->headers($workspace))
            ->assertOk();

        $this->getJson('/api/v1/reports/exports', $this->headers($workspace))
            ->assertOk()
            ->assertJsonPath('data.0.id', $exportId);

        // Someone else, in their own workspace, cannot — the id is real, but it
        // is not in their books, so there is nothing there to find.
        $intruder = $this->makeUser('outsider-export@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder books', 'TRY');

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/reports/exports/{$exportId}", $this->headers($intruderWorkspace))
            ->assertNotFound();

        // Nor does it appear in their listing, which is scoped the same way.
        $this->getJson('/api/v1/reports/exports', $this->headers($intruderWorkspace))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->get("/api/v1/reports/exports/{$exportId}/download", $this->headers($intruderWorkspace))
            ->assertNotFound();

        // And the owner's workspace still refuses a non-member outright.
        $this->getJson("/api/v1/reports/exports/{$exportId}", $this->headers($workspace))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'workspace_forbidden');
    }

    #[Test]
    public function a_persian_export_dates_the_rows_in_the_jalali_calendar(): void
    {
        [$user, $workspace] = $this->booksWithOneExpense();

        Sanctum::actingAs($user);

        $response = $this->post(
            '/api/v1/reports/expense-trend/export',
            [
                'format' => 'csv',
                'locale' => 'fa',
                'bucket' => 'day',
                'from' => '2026-03-07',
                'to' => '2026-03-07',
            ],
            $this->headers($workspace),
        );

        $response->assertOk();

        $rows = $this->parseCsv($response->getContent());

        // 7 March 2026 is 16 Esfand 1404 — the day the app shows for `fa`.
        $this->assertSame('1404-12-16', $rows[1][1]);
        $this->assertSame('1404-12-16', $rows[1][2]);

        // Latin digits, not ۱۴۰۴: the file is data before it is a document.
        $this->assertStringNotContainsString('۱۴۰۴', $response->getContent());

        // Same day, Gregorian, when the locale asks for it.
        $english = $this->post(
            '/api/v1/reports/expense-trend/export',
            [
                'format' => 'csv',
                'locale' => 'en',
                'bucket' => 'day',
                'from' => '2026-03-07',
                'to' => '2026-03-07',
            ],
            $this->headers($workspace),
        );

        $this->assertSame('2026-03-07', $this->parseCsv($english->getContent())[1][1]);
    }

    #[Test]
    public function a_large_export_becomes_a_job_and_is_collected_from_its_id(): void
    {
        [$user, $workspace] = $this->booksWithOneExpense();

        // Force the queued path rather than asking for four years of daily
        // buckets: which side of the line a request falls on is configuration,
        // and this test is about what happens on the far side of it.
        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/v1/reports/cash-flow/export',
            [
                'format' => 'csv',
                'locale' => 'en',
                'from' => '2026-03-01',
                'to' => '2026-03-31',
                'queued' => true,
            ],
            $this->headers($workspace),
        );

        // QUEUE_CONNECTION is sync under test, so the job has already run.
        $response->assertStatus(202);
        $exportId = $response->json('data.id');

        $poll = $this->getJson("/api/v1/reports/exports/{$exportId}", $this->headers($workspace));

        $poll->assertOk();
        $poll->assertJsonPath('data.status', ReportExport::STATUS_READY);
        $poll->assertJsonPath('data.report', 'cash-flow');
        $this->assertNotNull($poll->json('data.download_url'));

        $download = $this->get("/api/v1/reports/exports/{$exportId}/download", $this->headers($workspace));

        $download->assertOk();
        $this->assertStringContainsString('350.00', $download->getContent());
        $this->assertStringNotContainsString('35000', $download->getContent());
    }

    #[Test]
    public function the_row_limit_decides_between_a_response_and_a_job(): void
    {
        [$user, $workspace] = $this->booksWithOneExpense();

        Sanctum::actingAs($user);

        // 31 daily buckets, over a CSV limit of 10.
        config(['reports.exports.inline_row_limit.csv' => 10]);

        $this->postJson(
            '/api/v1/reports/cash-flow/export',
            ['format' => 'csv', 'bucket' => 'day', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $this->headers($workspace),
        )->assertStatus(202);

        // The same 31 rows are nothing for a CSV at the real limit.
        config(['reports.exports.inline_row_limit.csv' => 5000]);

        $this->post(
            '/api/v1/reports/cash-flow/export',
            ['format' => 'csv', 'bucket' => 'day', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $this->headers($workspace),
        )->assertOk();

        // The limit is per format: a PDF of the same report is far cheaper to
        // hand over as a link than to lay out inside the request.
        config(['reports.exports.inline_row_limit.pdf' => 10]);

        $this->postJson(
            '/api/v1/reports/cash-flow/export',
            ['format' => 'pdf', 'bucket' => 'day', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $this->headers($workspace),
        )->assertStatus(202);
    }

    #[Test]
    public function a_payee_that_looks_like_a_formula_is_not_one_when_the_file_opens(): void
    {
        $user = $this->makeUser('formula@example.test');
        $workspace = $this->makeWorkspace($user, currency: 'TRY');
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 1_000_000);

        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 1000,
            'currency' => 'TRY',
            'payee' => '=HYPERLINK("http://evil.test")',
            'occurred_at' => '2026-03-07 19:30:00',
        ]));

        Sanctum::actingAs($user);

        $csv = $this->post(
            '/api/v1/reports/top-merchants/export',
            ['format' => 'csv', 'locale' => 'en', 'from' => '2026-03-01', 'to' => '2026-03-31'],
            $this->headers($workspace),
        )->getContent();

        $rows = $this->parseCsv($csv);

        $this->assertSame("'=HYPERLINK(\"http://evil.test\")", $rows[1][1]);

        // The amount beside it is untouched — a leading minus is a number, not
        // a formula, and escaping it would corrupt every negative total.
        $this->assertSame('10.00', $rows[1][2]);
    }

    /** @return array{0:User,1:Workspace} */
    private function booksWithOneExpense(): array
    {
        $user = $this->makeUser('export@example.test');
        $workspace = $this->makeWorkspace($user, 'Books', 'TRY');
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 1_000_000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $restaurant = Category::query()->where('path', '/home/food/restaurant')->firstOrFail();

            // ₺350.00 — 35,000 minor units.
            app(RecordTransaction::class)->handle([
                'type' => 'expense',
                'account_id' => $account->id,
                'category_id' => $restaurant->id,
                'amount' => 35000,
                'currency' => 'TRY',
                'payee' => 'Migros',
                'occurred_at' => '2026-03-07 19:30:00',
            ]);
        });

        return [$user, $workspace];
    }

    /** @return array<string, string> */
    private function headers(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => $workspace->id];
    }

    /** @return list<list<string>> */
    private function parseCsv(string $csv): array
    {
        // The BOM is there so Excel reads UTF-8; it is not part of a field.
        $csv = ltrim($csv, "\u{FEFF}");
        $rows = [];

        foreach (explode("\n", trim($csv)) as $line) {
            $rows[] = str_getcsv(trim($line, "\r"), escape: '');
        }

        return $rows;
    }
}
