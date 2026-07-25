<?php

declare(strict_types=1);

namespace Modules\Alerts\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Alerts\Models\AlertPreference;
use Modules\Alerts\Models\ChannelKeys;
use Modules\Core\Support\WorkspaceContext;

final class AlertPreferenceController
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->preference($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['boolean'],
            'quiet_hours_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'timezone' => ['sometimes', 'nullable', 'timezone'],
        ]);

        $preference = $this->preference($request);
        $preference->fill($data)->save();

        return response()->json(['data' => $this->present($preference)]);
    }

    private function preference(Request $request): AlertPreference
    {
        $workspace = $this->context->require();
        $userId = (int) $request->user()->id;

        return AlertPreference::forMember($workspace->id, $userId)
            ?? new AlertPreference(['workspace_id' => $workspace->id, 'user_id' => $userId]);
    }

    /** @return array<string, mixed> */
    private function present(AlertPreference $preference): array
    {
        $channels = [];

        foreach (ChannelKeys::ALL as $key) {
            $channels[$key] = $preference->enables($key);
        }

        return [
            'channels' => $channels,
            'quiet_hours_start' => $preference->quiet_hours_start,
            'quiet_hours_end' => $preference->quiet_hours_end,
            'timezone' => $preference->timezone,
        ];
    }
}
