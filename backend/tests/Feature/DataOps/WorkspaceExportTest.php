<?php

declare(strict_types=1);

namespace Tests\Feature\DataOps;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Core\Models\WorkspaceMember;
use Modules\DataOps\Models\DataExport;
use Modules\Documents\Models\Document;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

final class WorkspaceExportTest extends DataOpsTestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['dataops.exports.disk' => 'local', 'documents.disk' => 'local']);

        Storage::fake('local');
    }

    #[Test]
    public function every_transaction_is_exported_with_its_amount_as_a_decimal_string(): void
    {
        [$user, $workspace] = $this->world();

        // ₺350.00, which the ledger holds as 35000 kuruş.
        $this->recordExpense($workspace, 35000, 'Rent');
        $this->recordExpense($workspace, 1250, 'Coffee');

        Sanctum::actingAs($user);

        $export = $this->requestExport($workspace);

        $rows = $this->csvRows($this->archiveContents($export)['transactions.csv']);

        $this->assertCount(2, $rows);

        $amounts = array_column($rows, 'amount');
        sort($amounts);

        $this->assertSame(['12.50', '350.00'], $amounts);

        // The base amount is money too, and just as wrong in minor units.
        $this->assertSame(['12.50', '350.00'], collect($rows)->pluck('base_amount')->sort()->values()->all());
    }

    #[Test]
    public function the_archive_carries_the_promised_files_and_the_same_data_as_json(): void
    {
        [$user, $workspace] = $this->world();

        $this->recordExpense($workspace, 35000, 'Rent');

        Sanctum::actingAs($user);

        $document = $this->uploadDocument($workspace, 'receipt.pdf');

        $export = $this->requestExport($workspace);
        $contents = $this->archiveContents($export);

        foreach (['transactions.csv', 'accounts.csv', 'categories.csv', 'budgets.csv', 'invoices.csv', 'data.json'] as $entry) {
            $this->assertArrayHasKey($entry, $contents);
        }

        $this->assertArrayHasKey("documents/{$document->id}-receipt.pdf", $contents);

        $data = json_decode($contents['data.json'], true);

        $this->assertSame($workspace->id, $data['workspace']['id']);
        $this->assertSame('350.00', $data['tables']['transactions'][0]['amount']);
        $this->assertSame(
            $this->csvRows($contents['transactions.csv'])[0]['amount'],
            $data['tables']['transactions'][0]['amount'],
        );
        $this->assertSame($document->id, $data['documents'][0]['id']);
    }

    #[Test]
    public function an_export_contains_only_the_requesting_workspaces_data(): void
    {
        [$user, $workspace] = $this->world();
        [$rival, $rivalWorkspace] = $this->world('rival@example.test', 'Rival books');

        $mine = $this->recordExpense($workspace, 35000, 'My rent');
        $theirs = $this->recordExpense($rivalWorkspace, 99900, 'Their rent');

        Sanctum::actingAs($user);

        $contents = $this->archiveContents($this->requestExport($workspace));
        $rows = $this->csvRows($contents['transactions.csv']);

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows[0]['id']);
        $this->assertStringNotContainsString($theirs->id, $contents['transactions.csv']);
        $this->assertStringNotContainsString('Their rent', $contents['data.json']);

        // And nothing of the rival's accounts came along either.
        $accountNames = array_column($this->csvRows($contents['accounts.csv']), 'workspace_id');
        $this->assertSame([$workspace->id], array_values(array_unique($accountNames)));

        $this->assertNotSame($user->id, $rival->id);
    }

    #[Test]
    public function a_plain_member_cannot_export_the_workspace(): void
    {
        [$owner, $workspace] = $this->world();

        $member = $this->makeUser('member@example.test');
        $this->addMember($workspace, $member, WorkspaceMember::ROLE_MEMBER);

        Sanctum::actingAs($member);

        $this->postJson('/api/v1/exports', [], $this->headers($workspace))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'export_forbidden');

        $this->getJson('/api/v1/exports', $this->headers($workspace))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'export_forbidden');

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => DataExport::query()->count()));
        $this->assertNotSame($owner->id, $member->id);
    }

    #[Test]
    public function the_owner_and_an_admin_may_export(): void
    {
        [$owner, $workspace] = $this->world();

        $admin = $this->makeUser('admin@example.test');
        $this->addMember($workspace, $admin, WorkspaceMember::ROLE_ADMIN);

        foreach ([$owner, $admin] as $actor) {
            Sanctum::actingAs($actor);

            $this->postJson('/api/v1/exports', [], $this->headers($workspace))
                ->assertStatus(202)
                ->assertJsonPath('data.status', DataExport::STATUS_READY);
        }

        $this->assertSame(2, $this->inWorkspace($workspace, fn () => DataExport::query()->count()));

        $this->getJson('/api/v1/exports', $this->headers($workspace))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_ready_export_downloads_as_the_archive_that_was_built(): void
    {
        [$user, $workspace] = $this->world();
        $this->recordExpense($workspace, 35000, 'Rent');

        Sanctum::actingAs($user);

        $export = $this->requestExport($workspace);

        $response = $this->get("/api/v1/exports/{$export->id}/download", $this->headers($workspace));

        $response->assertOk();
        $this->assertSame(
            Storage::disk('local')->get((string) $export->path),
            $response->streamedContent(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'data_export.downloaded',
            'workspace_id' => $workspace->id,
        ]);
    }

    #[Test]
    public function another_workspaces_export_is_invisible(): void
    {
        [$owner, $ownerWorkspace] = $this->world();
        [$intruder, $intruderWorkspace] = $this->world('intruder@example.test', 'Intruder books');

        Sanctum::actingAs($owner);
        $export = $this->requestExport($ownerWorkspace);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/exports/{$export->id}/download", $this->headers($intruderWorkspace))
            ->assertNotFound();
    }

    #[Test]
    public function requesting_an_export_is_written_to_the_audit_trail(): void
    {
        [$user, $workspace] = $this->world();

        Sanctum::actingAs($user);

        $this->requestExport($workspace);

        foreach (['data_export.requested', 'data_export.completed'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
            ]);
        }
    }

    /** @return array{0: User, 1: Workspace} */
    private function world(string $email = 'ali@example.test', string $name = 'Personal'): array
    {
        $user = $this->makeUser($email);
        $workspace = $this->makeWorkspace($user, $name);

        $this->accounts[$workspace->id] = $this->makeAccount($workspace, 'Wallet', 'TRY', 1000000);

        return [$user, $workspace];
    }

    private function recordExpense(Workspace $workspace, int $minorUnits, string $description): Transaction
    {
        $account = $this->accounts[$workspace->id];

        return $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => $minorUnits,
            'currency' => 'TRY',
            'description' => $description,
        ]));
    }

    private function uploadDocument(Workspace $workspace, string $name): Document
    {
        $response = $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create($name, 8, 'application/pdf'),
        ], $this->headers($workspace));

        $response->assertCreated();

        return $this->inWorkspace(
            $workspace,
            fn () => Document::query()->findOrFail($response->json('data.id')),
        );
    }

    private function requestExport(Workspace $workspace): DataExport
    {
        $this->postJson('/api/v1/exports', [], $this->headers($workspace))
            ->assertStatus(202)
            ->assertJsonPath('data.status', DataExport::STATUS_READY);

        return $this->latestExport($workspace);
    }
}
