<?php

declare(strict_types=1);

namespace Modules\Banking\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Banking\Models\Bank;
use Modules\Core\Models\WorkspaceMember;

final class BankController
{
    public function index(): JsonResponse
    {
        $banks = Bank::query()->orderBy('name')->get();

        return response()->json([
            'data' => $banks->map(fn (Bank $bank) => $this->present($bank))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanWrite($request);

        $data = $request->validate([
            'id' => ['sometimes', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:160'],
            'branch' => ['nullable', 'string', 'max:160'],
            'swift' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'size:2'],
            'logo' => ['nullable', 'string', 'max:255'],
        ]);

        $bank = new Bank;

        if (! empty($data['id'])) {
            $bank->id = $data['id'];
        }

        $bank->fill($data)->save();

        return response()->json(['data' => $this->present($bank)], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->present(Bank::query()->findOrFail($id))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->assertCanWrite($request);

        $bank = Bank::query()->findOrFail($id);

        $bank->fill($request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'branch' => ['nullable', 'string', 'max:160'],
            'swift' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'size:2'],
            'logo' => ['nullable', 'string', 'max:255'],
        ]))->save();

        return response()->json(['data' => $this->present($bank)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->assertCanWrite($request);

        Bank::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function assertCanWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }

    /** @return array<string, mixed> */
    private function present(Bank $bank): array
    {
        return [
            'id' => $bank->id,
            'name' => $bank->name,
            'branch' => $bank->branch,
            'swift' => $bank->swift,
            'country' => $bank->country,
            'logo' => $bank->logo,
            'created_at' => $bank->created_at?->toIso8601String(),
            'updated_at' => $bank->updated_at?->toIso8601String(),
        ];
    }
}
