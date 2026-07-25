<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Documents\Models\Document;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Engines\DatabaseSearchEngine;
use Modules\Search\Support\TextNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersModuleProviders;
use Tests\Feature\LedgerTestCase;

/**
 * The promise of docs/02-modules.md §12: the user types `گوشت` and everything
 * comes back — expenses, receipts, categories — regardless of which keyboard
 * wrote the stored text.
 */
final class SearchTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersModuleProviders;

    private const MEAT = 'گوشت';

    #[Test]
    public function the_normalizer_folds_arabic_letters_onto_their_persian_twins(): void
    {
        $this->assertSame('یک', TextNormalizer::normalize('يك'));
        $this->assertSame('یک', TextNormalizer::normalize('یک'));
        $this->assertSame('خرید', TextNormalizer::normalize('خريد'));
        $this->assertSame('علی', TextNormalizer::normalize('على'));
    }

    #[Test]
    public function the_normalizer_turns_persian_and_arabic_digits_into_latin(): void
    {
        $this->assertSame('123', TextNormalizer::normalize('۱۲۳'));
        $this->assertSame('4567890', TextNormalizer::normalize('٤٥٦٧٨٩٠'));
        $this->assertSame('مبلغ 12300 ریال', TextNormalizer::normalize('مبلغ ۱۲۳۰۰ ریال'));
    }

    #[Test]
    public function the_normalizer_strips_harakat_and_tashdid(): void
    {
        $this->assertSame('کباب', TextNormalizer::normalize('کَبَاب'));
        $this->assertSame('محمد', TextNormalizer::normalize('مُحَمَّد'));
        $this->assertSame('گوشت', TextNormalizer::normalize('گوشْت'));
    }

    #[Test]
    public function the_normalizer_turns_a_zwnj_into_a_space(): void
    {
        $this->assertSame('می رود', TextNormalizer::normalize("می\u{200C}رود"));
        $this->assertSame('کتاب های من', TextNormalizer::normalize("کتاب\u{200C}های من"));
    }

    #[Test]
    public function the_normalizer_strips_the_kashida(): void
    {
        $this->assertSame('گوشت', TextNormalizer::normalize('گوشـــت'));
        $this->assertSame('سلام', TextNormalizer::normalize('سـلام'));
    }

    #[Test]
    public function the_normalizer_collapses_whitespace_and_lowercases(): void
    {
        $this->assertSame('ali reza', TextNormalizer::normalize("  ALI \n\t  Reza  "));
        $this->assertSame('', TextNormalizer::normalize(null));
        $this->assertSame('', TextNormalizer::normalize('   '));
    }

    #[Test]
    public function searching_finds_a_transaction_however_its_persian_was_typed(): void
    {
        [$user, $workspace] = $this->world();

        // Same word, three keyboards: plain Persian, Arabic ي/ك, and a kashida.
        $plain = $this->transaction($workspace, self::MEAT);
        $arabic = $this->transaction($workspace, 'خريد گوشت از قصابي');
        $stretched = $this->transaction($workspace, 'گوشـــت چرخ کرده');
        $unrelated = $this->transaction($workspace, 'بنزین');

        Sanctum::actingAs($user);

        $response = $this->search($workspace, self::MEAT);
        $response->assertOk();

        $ids = array_column($response->json('data.transactions'), 'id');

        $this->assertContains($plain->id, $ids);
        $this->assertContains($arabic->id, $ids);
        $this->assertContains($stretched->id, $ids);
        $this->assertNotContains($unrelated->id, $ids);
    }

    #[Test]
    public function a_persian_needle_finds_text_stored_with_arabic_letters(): void
    {
        [$user, $workspace] = $this->world();

        $stored = $this->transaction($workspace, 'خريد هفتگي');

        Sanctum::actingAs($user);

        // `خرید` with a Persian ی against `خريد` with an Arabic ي.
        $ids = array_column($this->search($workspace, 'خرید')->json('data.transactions'), 'id');

        $this->assertSame([$stored->id], $ids);
    }

    #[Test]
    public function searching_reaches_the_payee_and_the_notes(): void
    {
        [$user, $workspace] = $this->world();

        $byPayee = $this->transaction($workspace, 'خرید هفتگی', payee: 'قصابي گوشت تازه');
        $byNotes = $this->transaction($workspace, 'خرید هفتگی', notes: 'سفارش گوشـت برای مهمانی');

        Sanctum::actingAs($user);

        $ids = array_column($this->search($workspace, self::MEAT)->json('data.transactions'), 'id');

        $this->assertContains($byPayee->id, $ids);
        $this->assertContains($byNotes->id, $ids);
    }

    #[Test]
    public function searching_reaches_inside_the_ocr_text_of_a_document(): void
    {
        [$user, $workspace] = $this->world();

        $receipt = $this->document($workspace, 'receipt-01.jpg', "فروشگاه رفاه\nگوشت گوسفندی\nمبلغ ۱۲۳۰۰ ریال");
        $other = $this->document($workspace, 'lease.pdf', 'اجاره نامه آپارتمان');

        Sanctum::actingAs($user);

        $ids = array_column($this->search($workspace, self::MEAT)->json('data.documents'), 'id');
        $this->assertSame([$receipt->id], $ids);

        // The receipt printed Persian digits; the user types Latin ones.
        $byAmount = array_column($this->search($workspace, '12300')->json('data.documents'), 'id');
        $this->assertSame([$receipt->id], $byAmount);

        $byName = array_column($this->search($workspace, 'lease')->json('data.documents'), 'id');
        $this->assertSame([$other->id], $byName);
    }

    #[Test]
    public function one_response_carries_results_from_every_type(): void
    {
        [$user, $workspace] = $this->world();

        $transaction = $this->transaction($workspace, 'خريد گوشت');
        $document = $this->document($workspace, 'receipt.jpg', 'گوشـت گوسفندی');
        $category = $this->makeCategory($workspace, self::MEAT);

        Sanctum::actingAs($user);

        $response = $this->search($workspace, self::MEAT);

        $response->assertOk();
        $response->assertJsonPath('data.transactions.0.id', $transaction->id);
        $response->assertJsonPath('data.documents.0.id', $document->id);
        $response->assertJsonPath('data.categories.0.id', $category->id);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.normalized_query', self::MEAT);
    }

    #[Test]
    public function the_types_parameter_narrows_the_response(): void
    {
        [$user, $workspace] = $this->world();

        $this->transaction($workspace, 'خريد گوشت');
        $this->document($workspace, 'receipt.jpg', 'گوشت');
        $this->makeCategory($workspace, self::MEAT);

        Sanctum::actingAs($user);

        $response = $this->search($workspace, self::MEAT, ['types' => 'documents,categories']);

        $response->assertOk();
        $response->assertJsonMissingPath('data.transactions');
        $response->assertJsonPath('meta.types', ['documents', 'categories']);
        $response->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function the_limit_caps_each_group(): void
    {
        [$user, $workspace] = $this->world();

        for ($i = 0; $i < 5; $i++) {
            $this->transaction($workspace, "گوشت شماره {$i}");
        }

        Sanctum::actingAs($user);

        $response = $this->search($workspace, self::MEAT, ['limit' => 2]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data.transactions'));
        $response->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function a_wildcard_in_the_query_matches_nothing_by_itself(): void
    {
        [$user, $workspace] = $this->world();

        $this->transaction($workspace, self::MEAT);

        Sanctum::actingAs($user);

        $response = $this->search($workspace, '%');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function search_never_returns_another_workspaces_rows(): void
    {
        [$owner, $ownerWorkspace] = $this->world('owner@example.test', 'Owner books');
        [$intruder, $intruderWorkspace] = $this->world('intruder@example.test', 'Intruder books');

        $secret = $this->transaction($ownerWorkspace, 'گوشت مخصوص');
        $this->document($ownerWorkspace, 'owner-receipt.jpg', 'گوشت گوسفندی');
        $this->makeCategory($ownerWorkspace, self::MEAT);

        Sanctum::actingAs($intruder);

        $response = $this->search($intruderWorkspace, self::MEAT);

        $response->assertOk();
        $response->assertJsonPath('meta.total', 0);

        // Sending the owner's workspace id does not help either.
        $this->search($ownerWorkspace, self::MEAT)->assertForbidden();

        // And the owner still finds their own row, so the emptiness above was
        // isolation rather than a broken query.
        Sanctum::actingAs($owner);
        $this->search($ownerWorkspace, self::MEAT)
            ->assertOk()
            ->assertJsonPath('data.transactions.0.id', $secret->id);
    }

    #[Test]
    public function the_engine_is_bound_to_the_database_implementation(): void
    {
        $this->assertInstanceOf(DatabaseSearchEngine::class, app(SearchEngine::class));
    }

    #[Test]
    public function the_engine_returns_nothing_when_no_workspace_is_active(): void
    {
        [, $workspace] = $this->world();
        $this->transaction($workspace, self::MEAT);

        $results = app(SearchEngine::class)->search(self::MEAT);

        $this->assertSame([], $results[SearchEngine::TYPE_TRANSACTIONS]);
        $this->assertSame([], $results[SearchEngine::TYPE_DOCUMENTS]);
        $this->assertSame([], $results[SearchEngine::TYPE_CATEGORIES]);
    }

    #[Test]
    public function an_empty_query_is_rejected(): void
    {
        [$user, $workspace] = $this->world();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/search?q=', $this->headers($workspace))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    /** @return array{0: User, 1: Workspace} */
    private function world(string $email = 'ali@example.test', string $name = 'Personal'): array
    {
        $user = $this->makeUser($email);
        $workspace = $this->makeWorkspace($user, $name);

        $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        return [$user, $workspace];
    }

    private function transaction(
        Workspace $workspace,
        string $description,
        ?string $payee = null,
        ?string $notes = null,
    ): Transaction {
        return $this->inWorkspace($workspace, function () use ($description, $payee, $notes): Transaction {
            $account = Account::query()->where('name', 'Wallet')->firstOrFail();

            return app(RecordTransaction::class)->handle([
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => 1000,
                'currency' => 'TRY',
                'description' => $description,
                'payee' => $payee,
                'notes' => $notes,
            ]);
        });
    }

    private function document(Workspace $workspace, string $name, string $ocrText): Document
    {
        return $this->inWorkspace($workspace, fn () => Document::query()->create([
            'disk' => 'local',
            'path' => "workspaces/{$workspace->id}/documents/{$name}",
            'original_name' => $name,
            'mime' => 'image/jpeg',
            'size' => 1024,
            'kind' => Document::KIND_RECEIPT,
            'ocr_status' => Document::OCR_DONE,
            'ocr_text' => $ocrText,
        ]));
    }

    /** @param  array<string, string|int>  $extra */
    private function search(Workspace $workspace, string $query, array $extra = []): TestResponse
    {
        $parameters = http_build_query(['q' => $query] + $extra);

        return $this->getJson("/api/v1/search?{$parameters}", $this->headers($workspace));
    }

    /** @return array<string, string> */
    private function headers(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => $workspace->id, 'Accept' => 'application/json'];
    }
}
