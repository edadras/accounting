<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Billing\Http\Resources\PlanResource;
use Modules\Billing\Models\Plan;

final class PlanController
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()->where('is_public', true)->orderBy('rank')->get();

        return response()->json(['data' => PlanResource::collection($plans)]);
    }
}
