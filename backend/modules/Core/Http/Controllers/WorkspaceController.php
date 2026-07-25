<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Actions\CreateWorkspace;
use Modules\Core\Models\Workspace;

final class WorkspaceController
{
    public function index(Request $request): JsonResponse
    {
        $workspaces = $request->user()
            ->workspaces()
            ->whereNull('archived_at')
            ->orderBy('workspaces.created_at')
            ->get();

        return response()->json([
            'data' => $workspaces->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
                'base_currency' => $workspace->base_currency,
                'locale' => $workspace->locale,
                'calendar' => $workspace->calendar,
                'icon' => $workspace->icon,
                'color' => $workspace->color,
                'role' => $workspace->pivot->role,
            ])->all(),
        ]);
    }

    public function store(Request $request, CreateWorkspace $createWorkspace): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Workspace::TYPES)],
            'base_currency' => ['required', Rule::in(Currency::codes())],
            'locale' => ['nullable', Rule::in(['fa', 'en', 'tr', 'ar'])],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $workspace = $createWorkspace->handle(
            owner: $request->user(),
            name: $data['name'],
            type: $data['type'],
            baseCurrency: $data['base_currency'],
            locale: $data['locale'] ?? $request->user()->locale ?? 'fa',
            timezone: $data['timezone'] ?? 'Asia/Tehran',
        );

        return response()->json([
            'data' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
                'base_currency' => $workspace->base_currency,
                'role' => 'owner',
            ],
        ], 201);
    }
}
