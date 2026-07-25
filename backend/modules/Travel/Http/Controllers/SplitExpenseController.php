<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\WorkspaceMember;
use Modules\Travel\Actions\SplitExpense as SplitExpenseAction;
use Modules\Travel\Http\Controllers\Concerns\ResolvesTrip;
use Modules\Travel\Http\Requests\StoreSplitExpenseRequest;
use Modules\Travel\Http\Resources\SplitExpenseResource;
use Modules\Travel\Models\SplitExpense;

/**
 * @phpstan-import-type SplitExpensePayload from SplitExpenseAction
 */
final class SplitExpenseController
{
    use ResolvesTrip;

    public function index(Request $request, string $tripId): JsonResponse
    {
        $trip = $this->trip($tripId);

        $query = SplitExpense::query()
            ->where('trip_id', $trip->id)
            ->with(['shares', 'trip'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($payerId = $request->query('payer_member_id')) {
            $query->where('payer_member_id', $payerId);
        }

        if ($from = $request->query('from')) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('occurred_at', '<=', $to);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => SplitExpenseResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StoreSplitExpenseRequest $request, string $tripId, SplitExpenseAction $split): JsonResponse
    {
        $trip = $this->trip($tripId);

        /** @var SplitExpensePayload $payload */
        $payload = $request->validated();

        $expense = $split->handle($trip, $payload);

        return (new SplitExpenseResource($expense->load(['shares', 'trip'])))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, string $tripId, string $expenseId): JsonResponse
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);

        $trip = $this->trip($tripId);

        $expense = SplitExpense::query()
            ->where('trip_id', $trip->id)
            ->findOrFail($expenseId);

        // The shares stay with the soft-deleted expense: balances read shares
        // through their expense, so a deleted expense drops out of the
        // settlement without its history being destroyed.
        $expense->delete();

        return response()->json(status: 204);
    }
}
