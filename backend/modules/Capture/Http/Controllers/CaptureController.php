<?php

declare(strict_types=1);

namespace Modules\Capture\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Capture\Actions\CaptureQr;
use Modules\Capture\Actions\CaptureSms;
use Modules\Capture\Actions\CaptureText;
use Modules\Capture\Actions\ConfirmCapturedMessage;
use Modules\Capture\Http\Resources\CaptureMessageResource;
use Modules\Capture\Models\CaptureMessage;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Http\Resources\TransactionResource;

/**
 * Four ways in, one thing out: a draft awaiting a person.
 *
 * None of these endpoints writes to the ledger. `confirm` does, and it is a
 * separate call the user has to make — the same shape as the AI module's draft
 * flow, for the same reason (docs/08-ai-layer.md, opening rule).
 */
final class CaptureController
{
    public function text(Request $request, CaptureText $capture): JsonResponse
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);

        return $this->created($capture->handle($data['text'], $request->user()?->id));
    }

    public function sms(Request $request, CaptureSms $capture): JsonResponse
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'sender' => ['required', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:2000'],
            'received_at' => ['required', 'date'],
        ]);

        return $this->created($capture->handle(
            $data['sender'],
            $data['body'],
            CarbonImmutable::parse($data['received_at']),
            $request->user()?->id,
        ));
    }

    public function qr(Request $request, CaptureQr $capture): JsonResponse
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'payload' => ['required', 'string', 'max:4000'],
        ]);

        return $this->created($capture->handle($data['payload'], $request->user()?->id));
    }

    public function index(Request $request): JsonResponse
    {
        $query = CaptureMessage::query()->with('draft')->orderByDesc('received_at')->orderByDesc('id');

        if ($channel = $request->query('channel')) {
            $query->where('channel', (string) $channel);
        }

        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }

        $messages = $query->limit(min((int) $request->query('limit', 50), 200))->get();

        return response()->json(['data' => CaptureMessageResource::collection($messages)]);
    }

    public function show(string $id): JsonResponse
    {
        return (new CaptureMessageResource(CaptureMessage::query()->with('draft')->findOrFail($id)))->response();
    }

    public function confirm(Request $request, ConfirmCapturedMessage $confirm, string $id): JsonResponse
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

        $message = CaptureMessage::query()->with('draft')->findOrFail($id);

        return (new TransactionResource($confirm->handle($message, $overrides)))
            ->response()
            ->setStatusCode(201);
    }

    private function created(CaptureMessage $message): JsonResponse
    {
        return (new CaptureMessageResource($message->load('draft')))->response()->setStatusCode(201);
    }

    private function authorizeWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }
}
