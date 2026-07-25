<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payroll\Actions\StoreTaxRuleSet;
use Modules\Payroll\Http\Requests\StoreTaxRuleRequest;
use Modules\Payroll\Http\Resources\TaxRuleResource;
use Modules\Payroll\Models\PayrollTaxRule;

final class TaxRuleController
{
    public function index(Request $request): JsonResponse
    {
        $query = PayrollTaxRule::query()
            ->orderBy('country')
            ->orderByDesc('effective_from');

        if (is_string($country = $request->query('country'))) {
            $query->where('country', strtoupper($country));
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return response()->json([
            'data' => TaxRuleResource::collection($query->get()),
        ]);
    }

    public function store(StoreTaxRuleRequest $request, StoreTaxRuleSet $store): JsonResponse
    {
        /** @var array{country:string,rules:array<string, mixed>} $data */
        $data = $request->validated();

        $rule = $store->handle($data);

        return (new TaxRuleResource($rule))->response()->setStatusCode(201);
    }
}
