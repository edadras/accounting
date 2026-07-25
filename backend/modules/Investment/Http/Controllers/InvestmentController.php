<?php

declare(strict_types=1);

namespace Modules\Investment\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;
use Modules\Core\Support\WorkspaceContext;
use Modules\Investment\Actions\RecordInvestmentTrade;
use Modules\Investment\Http\Resources\InvestmentResource;
use Modules\Investment\Http\Resources\InvestmentTransactionResource;
use Modules\Investment\Models\Investment;
use Modules\Investment\Models\InvestmentTransaction;
use Modules\Ledger\Actions\ExchangeRateResolver;

final class InvestmentController
{
    public function index(Request $request, WorkspaceContext $context, ExchangeRateResolver $rates): JsonResponse
    {
        $investments = Investment::query()
            ->when($request->query('kind'), fn ($q, $kind) => $q->ofKind((string) $kind))
            ->orderBy('name')
            ->get();

        // Positions can be held in different currencies, so a plain sum would
        // be meaningless. Totalling in the workspace base currency is the only
        // honest answer.
        $baseCurrency = Currency::of($context->baseCurrency());
        $value = 0;
        $profit = 0;

        foreach ($investments as $investment) {
            $rate = $rates->rate($investment->priceCurrency(), $baseCurrency);
            $value += $investment->currentValue()->convertTo($baseCurrency, $rate)->minorUnits;
            $profit += $investment->totalProfit()->convertTo($baseCurrency, $rate)->minorUnits;
        }

        return response()->json([
            'data' => InvestmentResource::collection($investments),
            'meta' => [
                'current_value' => [
                    'value' => $value,
                    'currency' => $baseCurrency->code,
                    'minor_unit' => $baseCurrency->minorUnit,
                ],
                'total_profit' => [
                    'value' => $profit,
                    'currency' => $baseCurrency->code,
                    'minor_unit' => $baseCurrency->minorUnit,
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanWrite($request);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(Investment::KINDS)],
            'symbol' => ['nullable', 'string', 'max:32'],
            'currency' => ['required', Rule::in(Currency::codes())],

            // A decimal string, never a float: JSON floats cannot hold
            // 0.00318 without drifting.
            'quantity' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,8})?$/'],

            // Integer minor units per unit held.
            'avg_buy_price' => ['nullable', 'integer', 'min:0'],
            'current_price' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $investment = new Investment;

        if (! empty($data['id'])) {
            $investment->id = $data['id'];
        }

        $investment->fill($data);
        $investment->quantity = $data['quantity'] ?? '0';
        $investment->save();

        return (new InvestmentResource($investment))->response()->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $investment = Investment::query()
            ->with(['transactions' => fn ($q) => $q->orderByDesc('occurred_at')])
            ->findOrFail($id);

        return (new InvestmentResource($investment))->response();
    }

    public function trade(Request $request, string $id, RecordInvestmentTrade $record): JsonResponse
    {
        $this->assertCanWrite($request);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'action' => ['required', Rule::in(InvestmentTransaction::ACTIONS)],
            'quantity' => ['nullable', 'string', 'regex:/^\d+(\.\d{1,8})?$/'],
            'price' => ['nullable', 'integer', 'min:0'],
            'fee' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'transaction_id' => ['nullable', 'string', 'size:26'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        // The Idempotency-Key header wins over a body field: it is what the
        // retry layer actually sets.
        $data['investment_id'] = $id;
        $data['idempotency_key'] = $request->header('Idempotency-Key')
            ?? ($data['idempotency_key'] ?? null);

        $trade = $record->handle($data);

        return (new InvestmentTransactionResource($trade))->response()->setStatusCode(201);
    }

    public function performance(string $id): JsonResponse
    {
        $investment = Investment::query()->findOrFail($id);

        return response()->json([
            'data' => [
                'investment_id' => $investment->id,
                'quantity' => (string) $investment->quantity,
                'avg_buy_price' => InvestmentResource::money($investment->avgBuyPrice()),
                'current_price' => $investment->currentPrice() === null
                    ? null
                    : InvestmentResource::money($investment->currentPrice()),
                'cost_basis' => InvestmentResource::money($investment->costBasis()),
                'current_value' => InvestmentResource::money($investment->currentValue()),
                'realized_profit' => InvestmentResource::money($investment->realizedProfit()),
                'unrealized_profit' => InvestmentResource::money($investment->unrealizedProfit()),
                'total_profit' => InvestmentResource::money($investment->totalProfit()),
                'roi' => $investment->roi(),
            ],
        ]);
    }

    private function assertCanWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }
}
