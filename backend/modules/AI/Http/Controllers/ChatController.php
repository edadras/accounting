<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AI\Actions\AnswerQuestion;
use Modules\AI\Models\AiConversation;

final class ChatController
{
    public function __invoke(Request $request, AnswerQuestion $answer): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['sometimes', 'string', 'max:64'],
        ]);

        // Resolved through the scoped model, so a conversation id belonging to
        // another workspace is simply not found.
        $conversation = isset($data['conversation_id'])
            ? AiConversation::query()->findOrFail((string) $data['conversation_id'])
            : null;

        return response()->json([
            'data' => $answer->handle($data['question'], $conversation, $request->user()),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $conversation = AiConversation::query()->with('messages')->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'messages' => $conversation->messages->map(static fn ($message): array => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'tool_name' => $message->tool_name,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        // docs/08-ai-layer.md §8: chat history is the user's to delete.
        AiConversation::query()->findOrFail($id)->delete();

        return response()->json(status: 204);
    }
}
