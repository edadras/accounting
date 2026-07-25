<?php

declare(strict_types=1);

namespace Modules\Banking\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Banking\Actions\ClearCheck;
use Modules\Banking\Http\Resources\CheckResource;
use Modules\Banking\Models\Check;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Models\Account;

final class CheckController
{
    public function index(Request $request): JsonResponse
    {
        $checks = Check::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->string('direction')))
            ->when($request->filled('due_before'), fn ($q) => $q->whereDate('due_date', '<=', $request->string('due_before')))
            ->orderBy('due_date')
            ->get();

        return response()->json(['data' => CheckResource::collection($checks)->resolve($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanWrite($request);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'account_id' => ['required', 'string', 'size:26'],
            'direction' => ['required', Rule::in(Check::DIRECTIONS)],
            'check_number' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(Currency::codes())],
            'due_date' => ['required', 'date'],
            'status' => ['sometimes', Rule::in(Check::STATUSES)],
            'party_name' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string'],
        ]);

        // findOrFail runs inside the workspace scope, so an account id from
        // another workspace is simply not there.
        Account::query()->findOrFail($data['account_id']);

        $check = new Check;

        if (! empty($data['id'])) {
            $check->id = $data['id'];
        }

        $check->fill($data);
        $check->status ??= Check::STATUS_DRAFT;
        $check->save();

        return response()->json(['data' => (new CheckResource($check))->resolve($request)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $check = Check::query()->findOrFail($id);

        return response()->json(['data' => (new CheckResource($check))->resolve($request)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->assertCanWrite($request);

        $check = Check::query()->findOrFail($id);

        $check->fill($request->validate([
            'check_number' => ['sometimes', 'string', 'max:64'],
            'due_date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(Check::STATUSES)],
            'party_name' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string'],
        ]))->save();

        return response()->json(['data' => (new CheckResource($check))->resolve($request)]);
    }

    public function clear(Request $request, ClearCheck $clear, string $id): JsonResponse
    {
        $this->assertCanWrite($request);

        $check = Check::query()->findOrFail($id);

        $clearedAt = $request->filled('cleared_at')
            ? new \DateTimeImmutable((string) $request->string('cleared_at'))
            : null;

        $check = $clear->handle($check, $clearedAt);

        return response()->json(['data' => (new CheckResource($check))->resolve($request)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->assertCanWrite($request);

        Check::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function assertCanWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }
}
