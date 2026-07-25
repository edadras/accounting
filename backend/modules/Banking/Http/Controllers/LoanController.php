<?php

declare(strict_types=1);

namespace Modules\Banking\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Banking\Actions\GenerateAmortizationSchedule;
use Modules\Banking\Actions\PayInstallment;
use Modules\Banking\Exceptions\BankingException;
use Modules\Banking\Http\Resources\LoanInstallmentResource;
use Modules\Banking\Http\Resources\LoanResource;
use Modules\Banking\Models\Bank;
use Modules\Banking\Models\Loan;
use Modules\Banking\Models\LoanInstallment;
use Modules\Banking\Support\MoneyView;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Models\Account;

final class LoanController
{
    public function index(Request $request): JsonResponse
    {
        $loans = Loan::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('start_date')
            ->get();

        return response()->json(['data' => LoanResource::collection($loans)->resolve($request)]);
    }

    public function store(Request $request, GenerateAmortizationSchedule $schedule): JsonResponse
    {
        $this->assertCanWrite($request);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'bank_id' => ['nullable', 'string', 'size:26'],
            'account_id' => ['required', 'string', 'size:26'],
            'title' => ['nullable', 'string', 'max:160'],
            'principal' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(Currency::codes())],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
            'interest_type' => ['required', Rule::in(Loan::INTEREST_TYPES)],
            'installments_count' => ['required', 'integer', 'min:1', 'max:600'],
            'start_date' => ['required', 'date'],
            'penalty_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        Account::query()->findOrFail($data['account_id']);

        if (! empty($data['bank_id'])) {
            Bank::query()->findOrFail($data['bank_id']);
        }

        $loan = new Loan;

        if (! empty($data['id'])) {
            $loan->id = $data['id'];
        }

        $loan->fill($data);
        $loan->penalty_rate = $data['penalty_rate'] ?? 0;
        $loan->outstanding_balance = $data['principal'];
        $loan->status = Loan::STATUS_ACTIVE;
        $loan->save();

        // A loan without its schedule is not yet usable, so it is generated in
        // the same request rather than left for a second call to remember.
        $schedule->handle($loan);

        return response()->json([
            'data' => (new LoanResource($loan->fresh(['installments'])))->resolve($request),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $loan = Loan::query()->with('installments')->findOrFail($id);

        return response()->json(['data' => (new LoanResource($loan))->resolve($request)]);
    }

    public function schedule(Request $request, GenerateAmortizationSchedule $generator, string $id): JsonResponse
    {
        $loan = Loan::query()->findOrFail($id);

        if ($request->boolean('regenerate')) {
            $this->assertCanWrite($request);
            $generator->handle($loan);
            $loan->refresh();
        }

        $installments = $loan->installments()->get();

        $totalInterest = $installments->sum(fn (LoanInstallment $row) => $row->interest_part);
        $totalPrincipal = $installments->sum(fn (LoanInstallment $row) => $row->principal_part);

        return response()->json([
            'data' => LoanInstallmentResource::collection($installments)->resolve($request),
            'meta' => [
                'principal' => MoneyView::of($loan->principal, $loan->currency),
                'total_principal' => MoneyView::of((int) $totalPrincipal, $loan->currency),
                'total_interest' => MoneyView::of((int) $totalInterest, $loan->currency),
                'outstanding_balance' => MoneyView::of($loan->outstanding_balance, $loan->currency),
            ],
        ]);
    }

    public function payInstallment(Request $request, PayInstallment $pay, string $id, int $number): JsonResponse
    {
        $this->assertCanWrite($request);

        $loan = Loan::query()->findOrFail($id);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'paid_at' => ['nullable', 'date'],
            'penalty' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $installment = LoanInstallment::query()
            ->where('loan_id', $loan->id)
            ->where('number', $number)
            ->first();

        if ($installment === null) {
            throw BankingException::installmentNotFound($loan->id, $number);
        }

        $installment = $pay->handle($installment, (int) $data['amount'], [
            'paid_at' => isset($data['paid_at']) ? new \DateTimeImmutable($data['paid_at']) : null,
            'penalty' => $data['penalty'] ?? null,
            'notes' => $data['notes'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);

        return response()->json([
            'data' => (new LoanInstallmentResource($installment))->resolve($request),
            'meta' => [
                'loan' => (new LoanResource($loan->fresh()))->resolve($request),
            ],
        ]);
    }

    private function assertCanWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }
}
