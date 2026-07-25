<?php

declare(strict_types=1);

namespace Modules\Alerts\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Alerts\Exceptions\AlertException;
use Modules\Alerts\Http\Resources\AlertResource;
use Modules\Alerts\Models\Alert;
use Modules\Core\Http\Concerns\ResolvesCurrentUser;

final class AlertController
{
    use ResolvesCurrentUser;

    public function index(Request $request): JsonResponse
    {
        // Scoped to the caller as well as the workspace: an alert is addressed
        // to a person, and sharing books does not mean sharing their inbox.
        $query = Alert::query()
            ->where('user_id', $this->currentUser($request)->id)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if ($request->boolean('unread')) {
            $query->unread();
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return response()->json(['data' => AlertResource::collection($query->get())]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $alert = Alert::query()
            ->where('user_id', $this->currentUser($request)->id)
            ->find($id) ?? throw AlertException::alertNotFound($id);

        $alert->forceFill(['read_at' => CarbonImmutable::now()])->save();

        return response()->json(['data' => new AlertResource($alert)]);
    }
}
