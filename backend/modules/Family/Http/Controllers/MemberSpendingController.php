<?php

declare(strict_types=1);

namespace Modules\Family\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Family\Exceptions\FamilyException;
use Modules\Family\Http\Concerns\PresentsMoney;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Queries\MemberSpending;
use Modules\Family\Support\Period;

final class MemberSpendingController
{
    use PresentsMoney;

    public function __invoke(Request $request, MemberSpending $spending): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'string', 'size:7'],
            'member_id' => ['nullable', 'string', 'size:26'],
        ]);

        $period = isset($data['period']) ? Period::of($data['period']) : Period::containing();

        $rows = isset($data['member_id'])
            ? [$spending->forMember($this->member($data['member_id']), $period)]
            : $spending->forPeriod($period);

        return response()->json([
            'data' => array_map(fn (array $row): array => $this->present($row), $rows),
            'meta' => [
                'period' => $period->key,
                'from' => $period->start->toIso8601String(),
                'to' => $period->end->toIso8601String(),
            ],
        ]);
    }

    private function member(string $id): FamilyMember
    {
        return FamilyMember::query()->findOr($id, callback: fn () => throw FamilyException::memberNotFound($id));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'member_id' => $row['member_id'],
            'display_name' => $row['display_name'],
            'role' => $row['role'],
            'period' => $row['period'],
            'spent' => $this->presentMoney($row['spent']),
            'cap' => $this->presentMoney($row['cap']),
            'remaining' => $this->presentMoney($row['remaining']),
            'percentage' => $row['percentage'],
            'is_over_cap' => $row['is_over_cap'],
        ];
    }
}
