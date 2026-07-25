<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Buildings\Http\Concerns\AuthorizesWrites;
use Modules\Buildings\Http\Resources\BuildingUnitResource;
use Modules\Buildings\Models\Building;
use Modules\Buildings\Models\BuildingUnit;

final class BuildingUnitController
{
    use AuthorizesWrites;

    public function index(string $building): JsonResponse
    {
        $model = Building::query()->findOrFail($building);

        $units = $model->units()->orderBy('unit_no')->get();

        return response()->json([
            'data' => BuildingUnitResource::collection($units),
            'meta' => ['total' => $units->count()],
        ]);
    }

    public function store(Request $request, string $building): JsonResponse
    {
        $this->assertCanWrite($request);

        $model = Building::query()->findOrFail($building);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'unit_no' => ['required', 'string', 'max:32'],
            'area_m2' => ['nullable', 'numeric', 'min:0'],
            'residents_count' => ['nullable', 'integer', 'min:0'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'owner_contact' => ['nullable', 'string', 'max:120'],
            'tenant_name' => ['nullable', 'string', 'max:120'],
            'tenant_contact' => ['nullable', 'string', 'max:120'],
            'share_factor' => ['nullable', 'numeric', 'min:0'],
            'is_occupied' => ['nullable', 'boolean'],
        ]);

        $unit = new BuildingUnit;

        if (! empty($data['id'])) {
            $unit->id = $data['id'];
        }

        $unit->fill($data);
        $unit->building_id = $model->id;
        $unit->save();

        return (new BuildingUnitResource($unit))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $building, string $unit): JsonResponse
    {
        $this->assertCanWrite($request);

        $model = Building::query()->findOrFail($building);
        $target = $model->units()->findOrFail($unit);

        $data = $request->validate([
            'unit_no' => ['sometimes', 'string', 'max:32'],
            'area_m2' => ['sometimes', 'numeric', 'min:0'],
            'residents_count' => ['sometimes', 'integer', 'min:0'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'owner_contact' => ['nullable', 'string', 'max:120'],
            'tenant_name' => ['nullable', 'string', 'max:120'],
            'tenant_contact' => ['nullable', 'string', 'max:120'],
            'share_factor' => ['sometimes', 'numeric', 'min:0'],
            'is_occupied' => ['sometimes', 'boolean'],
        ]);

        $target->fill($data)->save();

        return (new BuildingUnitResource($target))->response();
    }

    public function destroy(Request $request, string $building, string $unit): JsonResponse
    {
        $this->assertCanWrite($request);

        $model = Building::query()->findOrFail($building);
        $model->units()->findOrFail($unit)->delete();

        return response()->json(status: 204);
    }
}
