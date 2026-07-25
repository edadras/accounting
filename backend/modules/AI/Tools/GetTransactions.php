<?php

declare(strict_types=1);

namespace Modules\AI\Tools;

use Modules\AI\Contracts\Tool;
use Modules\AI\Tools\Concerns\ReadsWindow;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;

final class GetTransactions implements Tool
{
    use ReadsWindow;

    public function name(): string
    {
        return 'get_transactions';
    }

    public function description(): string
    {
        return 'Individual transactions in a date window, optionally filtered by type or category.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Inclusive start date, YYYY-MM-DD.'],
                'to' => ['type' => 'string', 'description' => 'Inclusive end date, YYYY-MM-DD.'],
                'type' => ['type' => 'string', 'enum' => Transaction::TYPES],
                'category_id' => ['type' => 'string', 'description' => 'Restrict to this category and everything under it.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => (int) config('ai.chat.max_rows', 50)],
            ],
        ];
    }

    public function run(array $arguments): array
    {
        [$from, $to] = $this->window($arguments);

        $query = Transaction::query()
            ->with(['category:id,name', 'account:id,name'])
            ->between($from, $to)
            ->orderByDesc('occurred_at')
            ->limit($this->limit($arguments['limit'] ?? null));

        $type = (string) ($arguments['type'] ?? '');

        if (in_array($type, Transaction::TYPES, true)) {
            $query->ofType($type);
        }

        $categoryId = $arguments['category_id'] ?? null;

        if (is_string($categoryId) && $categoryId !== '') {
            // Resolved through the scoped model: an id from another workspace
            // simply does not exist here, so it filters to nothing rather than
            // reaching across.
            $category = Category::query()->find($categoryId);

            $category === null
                ? $query->whereRaw('1 = 0')
                : $query->inCategorySubtree($category);
        }

        $rows = $query->get()->map(fn (Transaction $transaction): array => [
            'id' => $transaction->id,
            'occurred_at' => $transaction->occurred_at->toDateString(),
            'type' => $transaction->type,
            'amount' => (int) $transaction->amount,
            'currency' => $transaction->currency,
            'description' => $transaction->description,
            'payee' => $transaction->payee,
            'category' => $transaction->category?->name,
            'account' => $transaction->account?->name,
        ])->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => count($rows),
            'transactions' => $rows,
        ];
    }
}
