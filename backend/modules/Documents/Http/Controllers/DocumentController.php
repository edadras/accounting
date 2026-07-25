<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;
use Modules\Core\Support\WorkspaceContext;
use Modules\Documents\Exceptions\DocumentException;
use Modules\Documents\Http\Resources\DocumentResource;
use Modules\Documents\Models\Document;
use Modules\Documents\Support\AttachableTypes;

final class DocumentController
{
    public function index(Request $request): JsonResponse
    {
        $query = Document::query()
            ->with('documentables')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($kind = $request->query('kind')) {
            $query->where('kind', (string) $kind);
        }

        if ($type = $request->query('attached_type')) {
            $query->attachedTo(
                AttachableTypes::resolve((string) $type),
                (string) $request->query('attached_id', ''),
            );
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => DocumentResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeWrite($request);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.(int) config('documents.max_upload_kilobytes'),
                // `mimetypes` reads the type from the file itself, unlike
                // `mimes`, which would trust the extension the client chose.
                'mimetypes:'.implode(',', (array) config('documents.allowed_mimetypes')),
            ],
            'kind' => ['sometimes', Rule::in(Document::KINDS)],
            'ocr_status' => ['sometimes', Rule::in(Document::OCR_STATUSES)],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $workspaceId = app(WorkspaceContext::class)->require()->id;
        $disk = (string) config('documents.disk');
        $mime = $file->getMimeType();

        $id = (string) Str::ulid();
        $extension = $file->extension() ?: $file->getClientOriginalExtension();
        $filename = $extension === '' ? $id : "{$id}.{$extension}";

        $checksum = hash_file('sha256', (string) $file->getRealPath());

        $path = Storage::disk($disk)->putFileAs("workspaces/{$workspaceId}/documents", $file, $filename);

        if ($path === false) {
            throw DocumentException::storageFailed($file->getClientOriginalName());
        }

        $document = new Document;
        $document->id = $id;
        $document->fill([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
            'size' => $file->getSize(),
            'kind' => $request->string('kind')->toString() ?: Document::kindForMime($mime),
            'ocr_status' => $request->string('ocr_status')->toString() ?: Document::OCR_PENDING,
            'uploaded_by' => $request->user()?->id,
            'checksum' => $checksum === false ? null : $checksum,
        ])->save();

        return (new DocumentResource($document->load('documentables')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $document = Document::query()->with('documentables')->findOrFail($id);

        return (new DocumentResource($document))->response();
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeWrite($request);

        $document = Document::query()->findOrFail($id);

        $document->documentables()->delete();
        $document->delete();

        // The blob stays: the row is only soft-deleted, so the document can be
        // restored. A purge job removes both once the retention window passes.

        return response()->json(status: 204);
    }

    public function attach(Request $request, string $id): JsonResponse
    {
        $this->authorizeWrite($request);

        $document = Document::query()->findOrFail($id);
        $record = $this->resolveRecord($request);

        $document->attachTo($record);

        return (new DocumentResource($document->load('documentables')))
            ->response()
            ->setStatusCode(201);
    }

    public function detach(Request $request, string $id): JsonResponse
    {
        $this->authorizeWrite($request);

        $document = Document::query()->findOrFail($id);

        $document->detachFrom($this->resolveRecord($request));

        return response()->json(status: 204);
    }

    /**
     * Turns the {type, id} pair in the request into a real record.
     *
     * The alias is looked up in a whitelist and the lookup runs through the
     * workspace scope, so neither an arbitrary class nor another workspace's
     * row can be named here.
     */
    private function resolveRecord(Request $request): Model
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            'id' => ['required', 'string', 'max:64'],
        ]);

        $class = AttachableTypes::resolve($data['type']);

        return $class::query()->find($data['id'])
            ?? throw DocumentException::attachableNotFound($data['type'], $data['id']);
    }

    private function authorizeWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }
}
