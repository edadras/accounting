<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\AI\Actions\ConfirmDraft;
use Modules\AI\Actions\ParseTransactionText;
use Modules\AI\Actions\StoreDraft;
use Modules\AI\Http\Resources\AiDraftResource;
use Modules\AI\Models\AiDraft;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Http\Resources\TransactionResource;

/**
 * Drafts in, transactions out — but only ever by way of a confirmation.
 *
 * `store` parses and returns a suggestion; `confirm` is a separate call the
 * user has to make. Collapsing the two would be a one-line convenience and
 * would break the rule the whole module is built around.
 */
final class DraftController
{
    public function index(Request $request): JsonResponse
    {
        $query = AiDraft::query()->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }

        $drafts = $query->limit(min((int) $request->query('limit', 50), 200))->get();

        return response()->json(['data' => AiDraftResource::collection($drafts)]);
    }

    public function show(string $id): JsonResponse
    {
        return (new AiDraftResource(AiDraft::query()->findOrFail($id)))->response();
    }

    public function store(Request $request, ParseTransactionText $parse, StoreDraft $store): JsonResponse
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $draft = $parse->handle($data['text']);

        $stored = $store->handle($draft, [
            'source' => AiDraft::SOURCE_TEXT,
            'input_text' => $data['text'],
            'created_by' => $request->user()?->id,
        ]);

        return (new AiDraftResource($stored))->response()->setStatusCode(201);
    }

    public function confirm(Request $request, ConfirmDraft $confirm, string $id): JsonResponse
    {
        $this->authorizeWrite($request);

        $overrides = $request->validate([
            'account_id' => ['sometimes', 'string', 'max:64'],
            'category_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'amount' => ['sometimes', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'max:8'],
            'occurred_at' => ['sometimes', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'type' => ['sometimes', Rule::in(['income', 'expense'])],
        ]);

        $transaction = $confirm->handle(AiDraft::query()->findOrFail($id), $overrides);

        return (new TransactionResource($transaction))->response()->setStatusCode(201);
    }

    public function discard(Request $request, ConfirmDraft $confirm, string $id): JsonResponse
    {
        $this->authorizeWrite($request);

        return (new AiDraftResource($confirm->discard(AiDraft::query()->findOrFail($id))))->response();
    }

    private function authorizeWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }
}
