<?php

declare(strict_types=1);

namespace Modules\Family\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Family\Exceptions\FamilyException;
use Modules\Family\Http\Concerns\AuthorizesWrites;
use Modules\Family\Http\Concerns\PresentsMoney;
use Modules\Family\Models\FamilyMember;
use Modules\Ledger\Exceptions\LedgerException;
use Modules\Ledger\Models\Account;

final class FamilyMemberController
{
    use AuthorizesWrites;
    use PresentsMoney;

    public function index(): JsonResponse
    {
        $members = FamilyMember::query()
            ->orderBy('display_name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $members->map(fn (FamilyMember $member) => $this->present($member))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanWrite($request);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'user_id' => ['nullable', 'integer'],
            'display_name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::in(FamilyMember::ROLES)],
            'birth_date' => ['nullable', 'date'],
            'monthly_allowance' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', Rule::in(Currency::codes())],
            'spending_cap' => ['nullable', 'integer', 'min:0'],
            'account_id' => ['nullable', 'string', 'size:26'],
        ]);

        $this->assertAccountExists($data['account_id'] ?? null);

        $member = new FamilyMember;

        if (! empty($data['id'])) {
            // Client-generated ULID: a member added offline keeps the identity
            // they were created with.
            $member->id = $data['id'];
        }

        $member->fill([
            'user_id' => $data['user_id'] ?? null,
            'display_name' => $data['display_name'],
            'role' => $data['role'],
            'birth_date' => $data['birth_date'] ?? null,
            'monthly_allowance' => $data['monthly_allowance'] ?? null,
            'currency' => $data['currency'],
            'spending_cap' => $data['spending_cap'] ?? null,
            'account_id' => $data['account_id'] ?? null,
        ]);

        $member->save();

        return response()->json(['data' => $this->present($member)], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->member($id))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->assertCanWrite($request);

        $member = $this->member($id);

        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(FamilyMember::ROLES)],
            'birth_date' => ['nullable', 'date'],
            'monthly_allowance' => ['nullable', 'integer', 'min:0'],
            'spending_cap' => ['nullable', 'integer', 'min:0'],
            'account_id' => ['nullable', 'string', 'size:26'],
        ]);

        if (array_key_exists('account_id', $data)) {
            $this->assertAccountExists($data['account_id']);
        }

        $member->fill($data)->save();

        return response()->json(['data' => $this->present($member)]);
    }

    private function member(string $id): FamilyMember
    {
        return FamilyMember::query()->findOr($id, callback: fn () => throw FamilyException::memberNotFound($id));
    }

    /** Accounts are looked up through the workspace scope, so another household's cannot be named. */
    private function assertAccountExists(?string $accountId): void
    {
        if ($accountId === null) {
            return;
        }

        Account::query()->findOr($accountId, callback: fn () => throw LedgerException::accountNotFound($accountId));
    }

    /** @return array<string, mixed> */
    private function present(FamilyMember $member): array
    {
        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'display_name' => $member->display_name,
            'role' => $member->role,
            'birth_date' => $member->birth_date?->toDateString(),
            'monthly_allowance' => $this->presentMoney($member->allowance()),
            'spending_cap' => $this->presentMoney($member->spendingCap()),
            'currency' => $member->currency,
            'account_id' => $member->account_id,
            'tag' => $member->tag(),
        ];
    }
}
