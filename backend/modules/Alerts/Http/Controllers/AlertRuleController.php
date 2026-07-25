<?php

declare(strict_types=1);

namespace Modules\Alerts\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Alerts\Exceptions\AlertException;
use Modules\Alerts\Http\Requests\StoreAlertRuleRequest;
use Modules\Alerts\Http\Resources\AlertRuleResource;
use Modules\Alerts\Models\AlertRule;

final class AlertRuleController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => AlertRuleResource::collection(AlertRule::query()->orderBy('type')->get()),
        ]);
    }

    public function store(StoreAlertRuleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $rule = new AlertRule;

        if (! empty($data['id'])) {
            // Client-generated ULID: a rule created offline keeps its identity.
            $rule->id = $data['id'];
        }

        $rule->fill($data)->save();

        return (new AlertRuleResource($rule))->response()->setStatusCode(201);
    }

    public function destroy(string $id): JsonResponse
    {
        $rule = AlertRule::query()->find($id) ?? throw AlertException::ruleNotFound($id);

        $rule->delete();

        return response()->json(null, 204);
    }
}
