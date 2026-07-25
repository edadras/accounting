<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Controllers;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Buildings\Actions\RecordBuildingExpense;
use Modules\Buildings\Http\Concerns\AuthorizesWrites;
use Modules\Buildings\Http\Concerns\PresentsMoney;
use Modules\Buildings\Models\Building;
use Modules\Buildings\Models\BuildingExpense;

final class BuildingExpenseController
{
    use AuthorizesWrites;
    use PresentsMoney;

    public function index(string $building): JsonResponse
    {
        $model = Building::query()->findOrFail($building);

        $expenses = $model->expenses()->orderByDesc('occurred_at')->get();

        return response()->json([
            'data' => $expenses->map(fn (BuildingExpense $expense) => [
                'id' => $expense->id,
                'building_id' => $expense->building_id,
                'category_id' => $expense->category_id,
                'amount' => $this->presentMoney($expense->money()),
                'occurred_at' => $expense->occurred_at->toIso8601String(),
                'description' => $expense->description,
                'transaction_id' => $expense->transaction_id,
            ])->all(),
        ]);
    }

    public function store(Request $request, string $building, RecordBuildingExpense $record): JsonResponse
    {
        $this->assertCanWrite($request);

        $model = Building::query()->findOrFail($building);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(Currency::codes())],
            'category_id' => ['nullable', 'string', 'size:26'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $expense = $record->handle(
            building: $model,
            amount: Money::of((int) $data['amount'], $data['currency']),
            categoryId: $data['category_id'] ?? null,
            occurredAt: isset($data['occurred_at']) ? new \DateTimeImmutable($data['occurred_at']) : null,
            description: $data['description'] ?? null,
        );

        return response()->json([
            'data' => [
                'id' => $expense->id,
                'building_id' => $expense->building_id,
                'category_id' => $expense->category_id,
                'amount' => $this->presentMoney($expense->money()),
                'occurred_at' => $expense->occurred_at->toIso8601String(),
                'description' => $expense->description,
                'transaction_id' => $expense->transaction_id,
            ],
        ], 201);
    }
}
