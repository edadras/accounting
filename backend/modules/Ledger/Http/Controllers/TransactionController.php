<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Http\Requests\StoreTransactionRequest;
use Modules\Ledger\Http\Resources\TransactionResource;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;

final class TransactionController
{
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()
            ->with(['account:id,name,currency,type', 'category:id,name,path,color,icon'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($type = $request->query('type')) {
            $query->ofType((string) $type);
        }

        if ($accountId = $request->query('account_id')) {
            $query->where('account_id', $accountId);
        }

        if ($categoryId = $request->query('category_id')) {
            // Filtering by "Food" must include Restaurant and Groceries too —
            // otherwise every parent category reads as empty.
            $category = Category::query()->find($categoryId);

            if ($category !== null) {
                $query->inCategorySubtree($category);
            }
        }

        if ($from = $request->query('from')) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('occurred_at', '<=', $to);
        }

        if ($search = $request->query('q')) {
            $needle = '%'.$search.'%';
            $query->where(function ($builder) use ($needle): void {
                $builder->where('description', 'like', $needle)
                    ->orWhere('payee', 'like', $needle)
                    ->orWhere('notes', 'like', $needle);
            });
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => TransactionResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StoreTransactionRequest $request, RecordTransaction $record): JsonResponse
    {
        $payload = $request->validated();

        // The Idempotency-Key header wins over a body field: it is what the
        // retry layer actually sets.
        $payload['idempotency_key'] = $request->header('Idempotency-Key')
            ?? ($payload['idempotency_key'] ?? null);

        $transaction = $record->handle($payload);

        return (new TransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $transaction = Transaction::query()
            ->with(['account', 'counterAccount', 'category', 'entries'])
            ->findOrFail($id);

        return (new TransactionResource($transaction))->response();
    }

    public function destroy(string $id): JsonResponse
    {
        $transaction = Transaction::query()->findOrFail($id);
        $accounts = [$transaction->account, $transaction->counterAccount];

        $transaction->entries()->delete();
        $transaction->delete();

        foreach (array_filter($accounts) as $account) {
            $account->recalculateBalance();
        }

        return response()->json(status: 204);
    }
}
