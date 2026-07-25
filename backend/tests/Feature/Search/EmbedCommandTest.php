<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\Embedding;
use Modules\Core\Models\Workspace;
use Modules\Documents\Models\Document;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersModuleProviders;
use Tests\Feature\LedgerTestCase;

/**
 * `search:embed` exists for the rows that predate semantic search — and for
 * the rows written while a remote embedding provider was configured, which are
 * deliberately not embedded inline.
 *
 * The property that matters is that running it is never a decision: it may be
 * run at any time, from cron, twice by accident, and it will only ever compute
 * the vectors that are actually missing.
 */
final class EmbedCommandTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersModuleProviders;

    #[Test]
    public function it_backfills_rows_that_predate_the_feature(): void
    {
        $workspace = $this->world('backfill@example.test');

        $transaction = $this->transaction($workspace, 'بنزین');
        $document = $this->document($workspace, 'receipt.jpg', 'تعمیرگاه');

        $this->forgetTheVectors();
        $this->assertSame(0, $this->vectorCount());

        $this->artisan('search:embed')->assertSuccessful();

        $this->assertNotNull($this->vectorFor($transaction));
        $this->assertNotNull($this->vectorFor($document));
    }

    #[Test]
    public function running_it_twice_computes_nothing_the_second_time(): void
    {
        $workspace = $this->world('idempotent@example.test');

        $this->transaction($workspace, 'بنزین');
        $this->transaction($workspace, 'تعمیرگاه');

        $this->forgetTheVectors();

        $this->artisan('search:embed', ['--type' => 'transactions'])
            ->expectsOutputToContain('Embedded 2 record(s)')
            ->assertSuccessful();

        $after = $this->vectorSnapshot();

        $this->artisan('search:embed', ['--type' => 'transactions'])
            ->expectsOutputToContain('Embedded 0 record(s)')
            ->assertSuccessful();

        // Same count, same rows, same vectors — the second run was a no-op
        // rather than a rewrite that happened to land on the same numbers.
        $this->assertSame($after, $this->vectorSnapshot());
    }

    #[Test]
    public function it_only_computes_what_is_missing(): void
    {
        $workspace = $this->world('partial@example.test');

        $kept = $this->transaction($workspace, 'بنزین');
        $dropped = $this->transaction($workspace, 'تعمیرگاه');

        $keptVector = $this->vectorFor($kept);
        $this->forgetVectorFor($dropped);

        $this->assertSame(1, $this->vectorCount(Transaction::class));

        $this->artisan('search:embed', ['--type' => 'transactions'])
            ->expectsOutputToContain('Embedded 1 record(s)')
            ->assertSuccessful();

        $this->assertSame(2, $this->vectorCount(Transaction::class));

        // The one that already had a vector was left exactly as it was.
        $this->assertSame($keptVector, $this->vectorFor($kept));
        $this->assertNotNull($this->vectorFor($dropped));
    }

    #[Test]
    public function force_recomputes_rows_that_already_have_a_vector(): void
    {
        $workspace = $this->world('forced@example.test');

        $transaction = $this->transaction($workspace, 'بنزین');

        $this->artisan('search:embed', ['--type' => 'transactions', '--force' => true])
            ->expectsOutputToContain('Embedded 1 record(s)')
            ->assertSuccessful();

        $this->assertSame(1, $this->vectorCount(Transaction::class));
        $this->assertNotNull($this->vectorFor($transaction));
    }

    #[Test]
    public function the_workspace_option_confines_the_backfill(): void
    {
        $mine = $this->world('mine@example.test');
        $theirs = $this->world('theirs@example.test');

        $ours = $this->transaction($mine, 'بنزین');
        $others = $this->transaction($theirs, 'بنزین');

        $this->forgetTheVectors();

        $this->artisan('search:embed', ['--workspace' => $mine->id])->assertSuccessful();

        $this->assertNotNull($this->vectorFor($ours));
        $this->assertNull($this->vectorFor($others));
    }

    #[Test]
    public function the_type_option_confines_the_backfill(): void
    {
        $workspace = $this->world('typed@example.test');

        $transaction = $this->transaction($workspace, 'بنزین');
        $document = $this->document($workspace, 'receipt.jpg', 'تعمیرگاه');

        $this->forgetTheVectors();

        $this->artisan('search:embed', ['--type' => 'documents'])->assertSuccessful();

        $this->assertNull($this->vectorFor($transaction));
        $this->assertNotNull($this->vectorFor($document));
    }

    #[Test]
    public function an_unknown_type_is_refused_rather_than_silently_skipped(): void
    {
        $this->artisan('search:embed', ['--type' => 'invoices'])
            ->expectsOutputToContain('Unknown search type(s): invoices')
            ->assertFailed();
    }

    #[Test]
    public function an_unknown_workspace_is_refused(): void
    {
        $this->artisan('search:embed', ['--workspace' => '01JQZZZZZZZZZZZZZZZZZZZZZZ'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    private function world(string $email): Workspace
    {
        $workspace = $this->makeWorkspace($this->makeUser($email), 'Personal');

        $this->makeAccount($workspace, 'Wallet', 'TRY', 10_000_000);

        return $workspace;
    }

    private function transaction(Workspace $workspace, string $description): Transaction
    {
        return $this->inWorkspace($workspace, function () use ($description): Transaction {
            $account = Account::query()->where('name', 'Wallet')->firstOrFail();

            return app(RecordTransaction::class)->handle([
                'type' => Transaction::TYPE_EXPENSE,
                'account_id' => $account->id,
                'amount' => 100_00,
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

    private function vectorFor(Transaction|Document $model): ?string
    {
        return Embedding::query()
            ->withoutWorkspaceScope()
            ->where('owner_type', $model->getMorphClass())
            ->where('owner_id', $model->getKey())
            ->value('vector');
    }

    private function forgetVectorFor(Transaction|Document $model): void
    {
        Embedding::query()
            ->withoutWorkspaceScope()
            ->where('owner_id', $model->getKey())
            ->delete();
    }

    /** The state before the feature existed. */
    private function forgetTheVectors(): void
    {
        Embedding::query()->withoutWorkspaceScope()->delete();
    }

    private function vectorCount(?string $ownerClass = null): int
    {
        return Embedding::query()
            ->withoutWorkspaceScope()
            ->when($ownerClass !== null, fn ($query) => $query->where('owner_type', (new $ownerClass)->getMorphClass()))
            ->count();
    }

    /** @return array<string, string> */
    private function vectorSnapshot(): array
    {
        return Embedding::query()
            ->withoutWorkspaceScope()
            ->where('owner_type', (new Transaction)->getMorphClass())
            ->orderBy('owner_id')
            ->get(['owner_type', 'owner_id', 'model', 'vector'])
            ->mapWithKeys(fn (Embedding $row): array => [
                $row->owner_type.'|'.$row->owner_id.'|'.$row->model => (string) $row->vector,
            ])
            ->all();
    }
}
