<?php

declare(strict_types=1);

namespace Modules\Family\Http\Controllers;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Family\Actions\PayAllowance;
use Modules\Family\Exceptions\FamilyException;
use Modules\Family\Http\Concerns\AuthorizesWrites;
use Modules\Family\Http\Concerns\PresentsMoney;
use Modules\Family\Models\AllowancePayment;
use Modules\Family\Models\FamilyMember;

final class AllowanceController
{
    use AuthorizesWrites;
    use PresentsMoney;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'string', 'size:7'],
            'member_id' => ['nullable', 'string', 'size:26'],
        ]);

        $payments = AllowancePayment::query()
            ->when(isset($data['period']), fn ($query) => $query->where('period', $data['period']))
            ->when(isset($data['member_id']), fn ($query) => $query->where('member_id', $data['member_id']))
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $payments->map(fn (AllowancePayment $payment) => $this->present($payment))->all(),
        ]);
    }

    public function store(Request $request, string $memberId, PayAllowance $pay): JsonResponse
    {
        $this->assertCanWrite($request);

        $data = $request->validate([
            'payer_member_id' => ['required', 'string', 'size:26'],
            'period' => ['required', 'string', 'size:7'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'currency' => ['required_with:amount', Rule::in(Currency::codes())],
            'paid_at' => ['nullable', 'date'],
        ]);

        $payment = $pay->handle(
            member: $this->member($memberId),
            payer: $this->member($data['payer_member_id']),
            period: $data['period'],
            amount: isset($data['amount']) ? Money::of((int) $data['amount'], $data['currency']) : null,
            paidAt: isset($data['paid_at']) ? new \DateTimeImmutable($data['paid_at']) : null,
        );

        return response()->json(['data' => $this->present($payment)], 201);
    }

    private function member(string $id): FamilyMember
    {
        return FamilyMember::query()->findOr($id, callback: fn () => throw FamilyException::memberNotFound($id));
    }

    /** @return array<string, mixed> */
    private function present(AllowancePayment $payment): array
    {
        return [
            'id' => $payment->id,
            'member_id' => $payment->member_id,
            'payer_member_id' => $payment->payer_member_id,
            'period' => $payment->period,
            'amount' => $this->presentMoney($payment->money()),
            'transaction_id' => $payment->transaction_id,
            'paid_at' => $payment->paid_at->toIso8601String(),
        ];
    }
}
