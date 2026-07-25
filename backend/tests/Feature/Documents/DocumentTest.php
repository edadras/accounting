<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Core\Models\Workspace;
use Modules\Documents\Concerns\HasDocuments;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\Documentable;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersModuleProviders;
use Tests\Feature\LedgerTestCase;

/**
 * A transaction that has adopted HasDocuments.
 *
 * Modules\Ledger is frozen for this milestone, so the trait is exercised
 * against the same `transactions` rows through a stand-in that reports the
 * Transaction morph class — which is what the pivot actually stores.
 */
final class AttachableTransaction extends Model
{
    use BelongsToWorkspace;
    use HasDocuments;
    use HasUlidKey;

    protected $table = 'transactions';

    public function getMorphClass(): string
    {
        return Transaction::class;
    }
}

final class DocumentTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersModuleProviders;

    protected function setUp(): void
    {
        parent::setUp();

        config(['documents.disk' => 'local']);

        Storage::fake('local');
    }

    #[Test]
    public function an_upload_stores_the_file_and_records_it(): void
    {
        [$user, $workspace] = $this->world();

        Sanctum::actingAs($user);

        $response = $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf'),
        ], $this->headers($workspace));

        $response->assertCreated();
        $response->assertJsonPath('data.original_name', 'receipt.pdf');
        $response->assertJsonPath('data.mime', 'application/pdf');
        $response->assertJsonPath('data.kind', Document::KIND_OTHER);
        $response->assertJsonPath('data.ocr.status', Document::OCR_PENDING);

        $document = $this->inWorkspace($workspace, fn () => Document::query()->sole());

        $this->assertSame(12 * 1024, $document->size);
        $this->assertSame($user->id, (int) $document->uploaded_by);
        $this->assertNotNull($document->checksum);
        $this->assertStringStartsWith("workspaces/{$workspace->id}/documents/", $document->path);

        Storage::disk('local')->assertExists($document->path);
    }

    #[Test]
    public function an_image_upload_is_filed_as_a_photo(): void
    {
        [$user, $workspace] = $this->world();

        Sanctum::actingAs($user);

        $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->image('bill.jpg'),
        ], $this->headers($workspace))
            ->assertCreated()
            ->assertJsonPath('data.kind', Document::KIND_PHOTO);
    }

    #[Test]
    public function a_file_whose_real_type_is_not_allowed_is_refused(): void
    {
        [$user, $workspace] = $this->world();

        Sanctum::actingAs($user);

        // The name says PDF; the bytes say otherwise, and the bytes decide.
        $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('invoice.pdf', 4, 'application/x-httpd-php'),
        ], $this->headers($workspace))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function a_file_over_the_size_cap_is_refused(): void
    {
        [$user, $workspace] = $this->world();

        Sanctum::actingAs($user);

        $tooBig = ((int) config('documents.max_upload_kilobytes')) + 1;

        $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('huge.pdf', $tooBig, 'application/pdf'),
        ], $this->headers($workspace))
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function a_document_attaches_to_a_transaction_and_is_retrievable_through_it(): void
    {
        [$user, $workspace, $transaction] = $this->world();

        Sanctum::actingAs($user);

        $document = $this->upload($workspace, 'contract.pdf');

        $this->postJson("/api/v1/documents/{$document->id}/attach", [
            'type' => 'transaction',
            'id' => $transaction->id,
        ], $this->headers($workspace))
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.type', 'transaction')
            ->assertJsonPath('data.attachments.0.id', $transaction->id);

        $documents = $this->inWorkspace(
            $workspace,
            fn () => AttachableTransaction::query()->findOrFail($transaction->id)->documents,
        );

        $this->assertCount(1, $documents);
        $this->assertSame($document->id, $documents->first()->id);
        $this->assertSame('contract.pdf', $documents->first()->original_name);
    }

    #[Test]
    public function detaching_removes_the_link_but_keeps_the_document(): void
    {
        [$user, $workspace, $transaction] = $this->world();

        Sanctum::actingAs($user);

        $document = $this->upload($workspace, 'contract.pdf');
        $payload = ['type' => 'transaction', 'id' => $transaction->id];

        $this->postJson("/api/v1/documents/{$document->id}/attach", $payload, $this->headers($workspace))
            ->assertCreated();

        $this->postJson("/api/v1/documents/{$document->id}/detach", $payload, $this->headers($workspace))
            ->assertNoContent();

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Documentable::query()->count()));
        $this->getJson("/api/v1/documents/{$document->id}", $this->headers($workspace))->assertOk();
    }

    #[Test]
    public function the_same_document_attaches_to_two_records_without_a_second_copy_of_the_file(): void
    {
        [$user, $workspace, $transaction] = $this->world();
        $category = $this->makeCategory($workspace, 'Rent');

        Sanctum::actingAs($user);

        $document = $this->upload($workspace, 'lease.pdf');

        foreach ([['transaction', $transaction->id], ['category', $category->id]] as [$type, $id]) {
            $this->postJson("/api/v1/documents/{$document->id}/attach", [
                'type' => $type,
                'id' => $id,
            ], $this->headers($workspace))->assertCreated();
        }

        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Document::query()->count()));
        $this->assertSame(2, $this->inWorkspace($workspace, fn () => Documentable::query()->count()));
    }

    #[Test]
    public function attaching_the_same_record_twice_does_not_duplicate_the_link(): void
    {
        [$user, $workspace, $transaction] = $this->world();

        Sanctum::actingAs($user);

        $document = $this->upload($workspace, 'lease.pdf');

        for ($i = 0; $i < 2; $i++) {
            $this->postJson("/api/v1/documents/{$document->id}/attach", [
                'type' => 'transaction',
                'id' => $transaction->id,
            ], $this->headers($workspace))->assertCreated();
        }

        $this->assertSame(1, $this->inWorkspace($workspace, fn () => Documentable::query()->count()));
    }

    #[Test]
    public function attaching_to_an_unknown_type_is_refused(): void
    {
        [$user, $workspace] = $this->world();

        Sanctum::actingAs($user);

        $document = $this->upload($workspace, 'note.pdf');

        $this->postJson("/api/v1/documents/{$document->id}/attach", [
            'type' => 'invoice_line',
            'id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        ], $this->headers($workspace))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'forbidden_attachable_type');

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Documentable::query()->count()));
    }

    #[Test]
    public function a_class_name_in_the_request_is_never_instantiated(): void
    {
        [$user, $workspace] = $this->world();

        Sanctum::actingAs($user);

        $document = $this->upload($workspace, 'note.pdf');

        foreach (['App\Models\User', 'Modules\Core\Models\Workspace', 'transactions'] as $type) {
            $this->postJson("/api/v1/documents/{$document->id}/attach", [
                'type' => $type,
                'id' => (string) $user->id,
            ], $this->headers($workspace))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'forbidden_attachable_type');
        }
    }

    #[Test]
    public function attaching_to_a_record_from_another_workspace_is_refused(): void
    {
        [$user, $workspace] = $this->world();
        [, $otherWorkspace, $otherTransaction] = $this->world('rival@example.test', 'Rival books');

        Sanctum::actingAs($user);

        $document = $this->upload($workspace, 'note.pdf');

        $this->postJson("/api/v1/documents/{$document->id}/attach", [
            'type' => 'transaction',
            'id' => $otherTransaction->id,
        ], $this->headers($workspace))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'attachable_not_found');

        $this->assertNotSame($workspace->id, $otherWorkspace->id);
    }

    #[Test]
    public function another_workspaces_document_is_invisible(): void
    {
        [$owner, $ownerWorkspace] = $this->world('owner@example.test', 'Owner books');
        [$intruder, $intruderWorkspace] = $this->world('intruder@example.test', 'Intruder books');

        Sanctum::actingAs($owner);
        $document = $this->upload($ownerWorkspace, 'private.pdf');

        Sanctum::actingAs($intruder);

        // A valid header for a workspace the caller belongs to, plus somebody
        // else's id: only the global scope stops this one.
        $this->getJson("/api/v1/documents/{$document->id}", $this->headers($intruderWorkspace))
            ->assertNotFound();

        $this->deleteJson("/api/v1/documents/{$document->id}", headers: $this->headers($intruderWorkspace))
            ->assertNotFound();

        $this->getJson('/api/v1/documents', $this->headers($intruderWorkspace))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // And sending the owner's workspace id is refused before any lookup.
        $this->getJson("/api/v1/documents/{$document->id}", $this->headers($ownerWorkspace))
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    #[Test]
    public function deleting_a_document_removes_its_links(): void
    {
        [$user, $workspace, $transaction] = $this->world();

        Sanctum::actingAs($user);

        $document = $this->upload($workspace, 'lease.pdf');

        $this->postJson("/api/v1/documents/{$document->id}/attach", [
            'type' => 'transaction',
            'id' => $transaction->id,
        ], $this->headers($workspace))->assertCreated();

        $this->deleteJson("/api/v1/documents/{$document->id}", headers: $this->headers($workspace))
            ->assertNoContent();

        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Documentable::query()->count()));
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Document::query()->count()));
        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    /** @return array{0: User, 1: Workspace, 2: Transaction} */
    private function world(string $email = 'ali@example.test', string $name = 'Personal'): array
    {
        $user = $this->makeUser($email);
        $workspace = $this->makeWorkspace($user, $name);
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 500000);

        $transaction = $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 12500,
            'currency' => 'TRY',
            'description' => 'Rent',
        ]));

        return [$user, $workspace, $transaction];
    }

    /** @return array<string, string> */
    private function headers(Workspace $workspace): array
    {
        return ['X-Workspace-Id' => $workspace->id, 'Accept' => 'application/json'];
    }

    private function upload(Workspace $workspace, string $name): Document
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
}
