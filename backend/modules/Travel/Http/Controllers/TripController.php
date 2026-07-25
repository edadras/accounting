<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Travel\Http\Controllers\Concerns\ResolvesTrip;
use Modules\Travel\Http\Requests\StoreTripMemberRequest;
use Modules\Travel\Http\Requests\StoreTripRequest;
use Modules\Travel\Http\Resources\TripMemberResource;
use Modules\Travel\Http\Resources\TripResource;
use Modules\Travel\Models\Trip;
use Modules\Travel\Models\TripMember;

final class TripController
{
    use ResolvesTrip;

    public function index(): JsonResponse
    {
        $trips = Trip::query()
            ->with('members')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => TripResource::collection($trips)]);
    }

    public function store(StoreTripRequest $request): JsonResponse
    {
        $data = $request->validated();

        $trip = DB::transaction(function () use ($data): Trip {
            $trip = new Trip;

            if (! empty($data['id'])) {
                $trip->id = $data['id'];
            }

            $trip->fill([
                'name' => $data['name'],
                'destination' => $data['destination'] ?? null,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'base_currency' => $data['base_currency'],
            ]);

            $trip->save();

            foreach ($data['members'] ?? [] as $member) {
                $this->addMember($trip, $member);
            }

            return $trip;
        });

        return (new TripResource($trip->load('members')))->response()->setStatusCode(201);
    }

    public function show(string $tripId): JsonResponse
    {
        $trip = $this->trip($tripId);

        return (new TripResource($trip->load('members')))->response();
    }

    public function storeMember(StoreTripMemberRequest $request, string $tripId): JsonResponse
    {
        $trip = $this->trip($tripId);
        $member = $this->addMember($trip, $request->validated());

        return response()->json(['data' => TripMemberResource::present($member)], 201);
    }

    /** @param  array<string, mixed>  $data */
    private function addMember(Trip $trip, array $data): TripMember
    {
        $member = new TripMember;

        if (! empty($data['id'])) {
            $member->id = $data['id'];
        }

        $member->fill([
            'trip_id' => $trip->id,
            'user_id' => $data['user_id'] ?? null,
            'display_name' => $data['display_name'],
            'weight' => $data['weight'] ?? 1,
        ]);

        $member->save();

        return $member;
    }
}
