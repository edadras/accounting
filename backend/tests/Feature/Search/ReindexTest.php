<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Modules\Core\Models\Workspace;
use Modules\Documents\Models\Document;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Models\SearchEntry;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersModuleProviders;
use Tests\Feature\LedgerTestCase;

/**
 * The index only ever covered rows written after the module was installed,
 * because it is kept in step by model events. `search:reindex` is what makes the
 * rows that came before findable.
 */
final class ReindexTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersModuleProviders;

    private const MEAT = 'گوشت';

    #[Test]
    public function rows_that_predate_the_module_become_findable(): void
    {
        $workspace = $this->world('backfill@example.test');

        $transaction = $this->transaction($workspace, 'خرید گوشت');
        $document = $this->document($workspace, 'receipt.jpg', 'گوشت گوسفندی');
        $category = $this->makeCategory($workspace, self::MEAT);

        $this->forgetTheIndex();

        $this->assertSame([], $this->find($workspace, self::MEAT)[SearchEngine::TYPE_TRANSACTIONS]);

        $this->command('search:reindex')->assertSuccessful();

        $results = $this->find($workspace, self::MEAT);

        $this->assertSame([$transaction->id], array_column($results[SearchEngine::TYPE_TRANSACTIONS], 'id'));
        $this->assertSame([$document->id], array_column($results[SearchEngine::TYPE_DOCUMENTS], 'id'));
        $this->assertSame([$category->id], array_column($results[SearchEngine::TYPE_CATEGORIES], 'id'));
    }

    #[Test]
    public function running_it_twice_creates_no_duplicates(): void
    {
        $workspace = $this->world('idempotent@example.test');

        $transaction = $this->transaction($workspace, 'خرید گوشت');
        $this->document($workspace, 'receipt.jpg', 'گوشت');
        $this->makeCategory($workspace, self::MEAT);

        $this->forgetTheIndex();

        $this->command('search:reindex')->assertSuccessful();
        $afterFirst = $this->indexSize();

        $this->command('search:reindex')->assertSuccessful();

        $this->assertSame($afterFirst, $this->indexSize(), 'Reindexing must update rows in place, not add more.');
        $this->assertSame(1, SearchEntry::query()
            ->withoutWorkspaceScope()
            ->where('indexable_id', $transaction->id)
            ->count());

        // And a run over an index that was never emptied is a no-op too.
        $this->command('search:reindex')->assertSuccessful();
        $this->assertSame($afterFirst, $this->indexSize());
    }

    #[Test]
    public function the_type_option_narrows_what_is_rebuilt(): void
    {
        $workspace = $this->world('types@example.test');

        $transaction = $this->transaction($workspace, 'خرید گوشت');
        $document = $this->document($workspace, 'receipt.jpg', 'گوشت گوسفندی');

        $this->forgetTheIndex();

        $this->command('search:reindex --type=documents')->assertSuccessful();

        $results = $this->find($workspace, self::MEAT);

        $this->assertSame([], $results[SearchEngine::TYPE_TRANSACTIONS]);
        $this->assertSame([$document->id], array_column($results[SearchEngine::TYPE_DOCUMENTS], 'id'));

        $this->command('search:reindex --type=transactions,categories')->assertSuccessful();

        $this->assertSame(
            [$transaction->id],
            array_column($this->find($workspace, self::MEAT)[SearchEngine::TYPE_TRANSACTIONS], 'id'),
        );
    }

    #[Test]
    public function the_workspace_option_narrows_what_is_rebuilt(): void
    {
        $mine = $this->world('mine@example.test');
        $theirs = $this->world('theirs@example.test');

        $ours = $this->transaction($mine, 'خرید گوشت');
        $this->transaction($theirs, 'خرید گوشت');

        $this->forgetTheIndex();

        $this->command("search:reindex --workspace={$mine->id}")->assertSuccessful();

        $this->assertSame([$ours->id], array_column(
            $this->find($mine, self::MEAT)[SearchEngine::TYPE_TRANSACTIONS],
            'id',
        ));

        $this->assertSame([], $this->find($theirs, self::MEAT)[SearchEngine::TYPE_TRANSACTIONS]);
    }

    #[Test]
    public function an_unknown_type_or_workspace_is_refused(): void
    {
        $this->world('refused@example.test');

        $this->command('search:reindex --type=invoices')->assertFailed();
        $this->command('search:reindex --workspace=01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertFailed();
    }

    #[Test]
    public function a_soft_deleted_row_is_not_resurrected_by_a_reindex(): void
    {
        $workspace = $this->world('deleted@example.test');

        $kept = $this->transaction($workspace, 'خرید گوشت تازه');
        $removed = $this->transaction($workspace, 'خرید گوشت چرخ کرده');

        $this->inWorkspace($workspace, fn () => $removed->delete());

        $this->forgetTheIndex();
        $this->command('search:reindex')->assertSuccessful();

        $this->assertSame(
            [$kept->id],
            array_column($this->find($workspace, self::MEAT)[SearchEngine::TYPE_TRANSACTIONS], 'id'),
        );
    }

    // --- helpers -----------------------------------------------------------

    /**
     * artisan() hands back a bare exit code once console output is no longer
     * mocked, and only the PendingCommand carries the assertions.
     */
    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);

        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function world(string $email): Workspace
    {
        $workspace = $this->makeWorkspace($this->makeUser($email));

        $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        return $workspace;
    }

    /**
     * Empties the index, which is what the world looks like for every row that
     * was written before the Search module existed.
     */
    private function forgetTheIndex(): void
    {
        SearchEntry::query()->withoutWorkspaceScope()->delete();
    }

    private function indexSize(): int
    {
        return SearchEntry::query()->withoutWorkspaceScope()->count();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function find(Workspace $workspace, string $query): array
    {
        return $this->inWorkspace($workspace, fn () => app(SearchEngine::class)->search($query));
    }

    private function transaction(Workspace $workspace, string $description): Transaction
    {
        return $this->inWorkspace($workspace, function () use ($description): Transaction {
            $account = Account::query()->where('name', 'Wallet')->firstOrFail();

            return app(RecordTransaction::class)->handle([
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => 1000,
                'currency' => 'TRY',
                'description' => $description,
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
}
