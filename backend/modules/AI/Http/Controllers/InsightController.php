<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AI\Actions\GenerateInsights;
use Modules\AI\Http\Resources\AiInsightResource;
use Modules\AI\Models\AiInsight;

final class InsightController
{
    public function index(Request $request): JsonResponse
    {
        $query = AiInsight::query()->active()->orderByDesc('computed_at');

        if ($type = $request->query('type')) {
            $query->ofType((string) $type);
        }

        return response()->json([
            'data' => AiInsightResource::collection($query->limit(50)->get()),
        ]);
    }

    public function generate(GenerateInsights $generate): JsonResponse
    {
        return response()->json([
            'data' => AiInsightResource::collection($generate->handle()),
        ]);
    }

    public function dismiss(string $id): JsonResponse
    {
        $insight = AiInsight::query()->findOrFail($id);
        $insight->forceFill(['dismissed_at' => now()])->save();

        return (new AiInsightResource($insight))->response();
    }
}
