<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\Ledger\Models\Account;

final class AccountController
{
    public function index(Request $request, WorkspaceContext $context): JsonResponse
    {
        $accounts = Account::query()
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Accounts can be in different currencies, so a plain sum would be
        // meaningless. Totalling in the workspace base currency is the only
        // honest answer, and the rate used is reported alongside it.
        $baseCurrency = Currency::of($context->baseCurrency());
        $rates = app(ExchangeRateResolver::class);
        $total = 0;

        foreach ($accounts as $account) {
            $total += $account->balance()
                ->convertTo($baseCurrency, $rates->rate(Currency::of($account->currency), $baseCurrency))
                ->minorUnits;
        }

        return response()->json([
            'data' => $accounts->map(fn (Account $account) => $this->present($account))->all(),
            'meta' => [
                'total' => [
                    'value' => $total,
                    'currency' => $baseCurrency->code,
                    'minor_unit' => $baseCurrency->minorUnit,
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'currency' => ['required', Rule::in(Currency::codes())],
            'opening_balance' => ['nullable', 'integer'],
            'iban' => ['nullable', 'string', 'max:34'],
            'card_last4' => ['nullable', 'string', 'size:4'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $account = new Account;

        if (! empty($data['id'])) {
            $account->id = $data['id'];
        }

        $account->fill($data);
        $account->current_balance = $data['opening_balance'] ?? 0;
        $account->save();

        return response()->json(['data' => $this->present($account)], 201);
    }

    public function show(string $id): JsonResponse
    {
        $account = Account::query()->findOrFail($id);

        return response()->json(['data' => $this->present($account)]);
    }

    /** @return array<string, mixed> */
    private function present(Account $account): array
    {
        $money = $account->balance();

        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'currency' => $account->currency,
            'balance' => [
                'value' => $money->minorUnits,
                'currency' => $money->currency->code,
                'minor_unit' => $money->currency->minorUnit,
                'decimal' => $money->toDecimalString(),
            ],
            'opening_balance' => $account->opening_balance,
            'iban' => $account->iban,
            'card_last4' => $account->card_last4,
            'icon' => $account->icon,
            'color' => $account->color,
            'is_archived' => $account->isArchived(),
            'version' => $account->version,
        ];
    }
}
