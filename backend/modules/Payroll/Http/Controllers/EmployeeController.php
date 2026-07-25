<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payroll\Actions\RegisterEmployee;
use Modules\Payroll\Actions\SetCompensation;
use Modules\Payroll\Actions\UpdateEmployee;
use Modules\Payroll\Http\Requests\StoreCompensationRequest;
use Modules\Payroll\Http\Requests\StoreEmployeeRequest;
use Modules\Payroll\Http\Requests\UpdateEmployeeRequest;
use Modules\Payroll\Http\Resources\EmployeeResource;
use Modules\Payroll\Models\Employee;

final class EmployeeController
{
    public function index(Request $request): JsonResponse
    {
        $query = Employee::query()
            ->with('compensations')
            ->orderBy('name')
            ->orderBy('id');

        if (is_string($status = $request->query('status'))) {
            $query->where('status', $status);
        }

        if (is_string($country = $request->query('country'))) {
            $query->where('country', strtoupper($country));
        }

        if ($request->boolean('employable')) {
            $query->where('status', '!=', Employee::STATUS_ENDED);
        }

        if (is_string($search = $request->query('q')) && $search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => EmployeeResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StoreEmployeeRequest $request, RegisterEmployee $register): JsonResponse
    {
        /** @var array{name:string,started_on:string} $data */
        $data = $request->validated();

        $employee = $register->handle($data);

        return (new EmployeeResource($employee->load('compensations')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $employee = Employee::query()->with('compensations')->findOrFail($id);

        return (new EmployeeResource($employee))->response();
    }

    public function update(UpdateEmployeeRequest $request, string $id, UpdateEmployee $update): JsonResponse
    {
        $employee = Employee::query()->findOrFail($id);

        /** @var array{name?:string} $data */
        $data = $request->validated();

        $updated = $update->handle($employee, $data);

        return (new EmployeeResource($updated->load('compensations')))->response();
    }

    public function storeCompensation(StoreCompensationRequest $request, string $id, SetCompensation $set): JsonResponse
    {
        $employee = Employee::query()->findOrFail($id);

        /** @var array{amount:int,currency:string,period?:string|null,effective_from?:string|null} $data */
        $data = $request->validated();

        $set->handle($employee, $data);

        return (new EmployeeResource($employee->fresh('compensations') ?? $employee))
            ->response()
            ->setStatusCode(201);
    }
}
