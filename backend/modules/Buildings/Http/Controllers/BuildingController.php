<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Buildings\Http\Concerns\AuthorizesWrites;
use Modules\Buildings\Http\Resources\BuildingResource;
use Modules\Buildings\Models\Building;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Account;

final class BuildingController
{
    use AuthorizesWrites;

    public function index(): JsonResponse
    {
        $buildings = Building::query()
            ->with('fundAccount')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => BuildingResource::collection($buildings),
        ]);
    }

    public function store(Request $request, WorkspaceContext $context): JsonResponse
    {
        $this->assertCanWrite($request);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'charge_formula' => ['required', Rule::in(Building::FORMULAS)],
            'fund_account_id' => ['nullable', 'string', 'size:26'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
        ]);

        $building = DB::transaction(function () use ($data, $context): Building {
            $fundAccountId = $data['fund_account_id'] ?? null;

            if ($fundAccountId !== null) {
                Account::query()->findOrFail($fundAccountId);
            } else {
                // A building without a fund has nowhere to put charge income,
                // so one is opened with it rather than left for later.
                $fundAccountId = Account::query()->create([
                    'name' => $data['name'].' — Fund',
                    'type' => 'fund',
                    'currency' => $data['currency'] ?? $context->baseCurrency(),
                    'icon' => 'building',
                ])->id;
            }

            $building = new Building;

            if (! empty($data['id'])) {
                $building->id = $data['id'];
            }

            $building->fill([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'charge_formula' => $data['charge_formula'],
                'fund_account_id' => $fundAccountId,
                'units_count' => 0,
            ])->save();

            return $building;
        });

        return (new BuildingResource($building->load('fundAccount')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $building): JsonResponse
    {
        $model = Building::query()->with('fundAccount')->findOrFail($building);

        return (new BuildingResource($model))->response();
    }

    public function update(Request $request, string $building): JsonResponse
    {
        $this->assertCanWrite($request);

        $model = Building::query()->findOrFail($building);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'charge_formula' => ['sometimes', Rule::in(Building::FORMULAS)],
            'fund_account_id' => ['sometimes', 'string', 'size:26'],
        ]);

        if (isset($data['fund_account_id'])) {
            Account::query()->findOrFail($data['fund_account_id']);
        }

        $model->fill($data)->save();

        return (new BuildingResource($model->load('fundAccount')))->response();
    }
}
