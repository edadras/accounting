<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Workspace;
use Modules\Documents\Models\Document;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersModuleProviders;
use Tests\Feature\LedgerTestCase;

/**
 * The one cross-module coupling the Ledger models carry: a receipt hangs
 * directly off the transaction it belongs to.
 */
final class LedgerAttachmentTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersModuleProviders;

    #[Test]
    public function a_receipt_attaches_to_a_transaction_through_the_trait(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('receipt@example.test'));
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 1_000_00);

        $this->inWorkspace($workspace, function () use ($workspace, $account): void {
            $transaction = app(RecordTransaction::class)->handle([
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => 25_00,
                'currency' => 'TRY',
                'description' => 'خرید گوشت',
            ]);

            $receipt = $this->document($workspace, 'receipt.jpg');
            $unrelated = $this->document($workspace, 'lease.pdf');

            $link = $transaction->attachDocument($receipt);

            $this->assertSame($workspace->id, $link->workspace_id);
            $this->assertSame(26, strlen((string) $link->id), 'The link row must get a ULID like every other row.');

            $documents = $transaction->fresh()->documents;

            $this->assertSame([$receipt->id], $documents->pluck('id')->all());
            $this->assertNotContains($unrelated->id, $documents->pluck('id')->all());

            // Attaching the same receipt again is the same link, not a second one.
            $transaction->attachDocument($receipt);
            $this->assertCount(1, $transaction->fresh()->documents);

            $transaction->detachDocument($receipt);
            $this->assertCount(0, $transaction->fresh()->documents);
        });
    }

    #[Test]
    public function an_account_can_hold_its_own_paperwork(): void
    {
        $workspace = $this->makeWorkspace($this->makeUser('statement@example.test'));
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 1_000_00);

        $this->inWorkspace($workspace, function () use ($workspace, $account): void {
            $statement = $this->document($workspace, 'statement.pdf');

            $account->attachDocument($statement);

            $this->assertSame([$statement->id], $account->fresh()->documents->pluck('id')->all());
        });
    }

    #[Test]
    public function another_workspaces_transaction_cannot_reach_the_attachment(): void
    {
        $mine = $this->makeWorkspace($this->makeUser('mine-doc@example.test'));
        $myAccount = $this->makeAccount($mine, 'Wallet', 'TRY', 1_000_00);

        $transaction = $this->inWorkspace($mine, function () use ($mine, $myAccount): Transaction {
            $transaction = app(RecordTransaction::class)->handle([
                'type' => 'expense',
                'account_id' => $myAccount->id,
                'amount' => 10_00,
                'currency' => 'TRY',
            ]);

            $transaction->attachDocument($this->document($mine, 'private-receipt.jpg'));

            return $transaction;
        });

        $theirs = $this->makeWorkspace($this->makeUser('theirs-doc@example.test'));

        $this->assertCount(
            0,
            $this->inWorkspace($theirs, fn () => Transaction::query()->find($transaction->id)?->documents ?? []),
        );
    }

    private function document(Workspace $workspace, string $name): Document
    {
        return Document::query()->create([
            'disk' => 'local',
            'path' => "workspaces/{$workspace->id}/documents/{$name}",
            'original_name' => $name,
            'mime' => 'image/jpeg',
            'size' => 2048,
            'kind' => Document::KIND_RECEIPT,
            'ocr_status' => Document::OCR_PENDING,
        ]);
    }
}
