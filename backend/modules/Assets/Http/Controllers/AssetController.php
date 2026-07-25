<?php

declare(strict_types=1);

namespace Modules\Assets\Http\Controllers;

use App\Core\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Assets\Actions\CalculateDepreciation;
use Modules\Assets\Http\Resources\AssetResource;
use Modules\Assets\Models\Asset;
use Modules\Core\Models\WorkspaceMember;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\ExchangeRateResolver;

final class AssetController
{
    public function index(Request $request, WorkspaceContext $context, ExchangeRateResolver $rates): JsonResponse
    {
        $assets = Asset::query()
            ->when($request->query('kind'), fn ($q, $kind) => $q->ofKind((string) $kind))
            ->orderBy('name')
            ->get();

        // Assets can be valued in different currencies; only a base-currency
        // total means anything.
        $baseCurrency = Currency::of($context->baseCurrency());
        $total = 0;

        foreach ($assets as $asset) {
            $total += $asset->currentValue()
                ->convertTo($baseCurrency, $rates->rate($asset->priceCurrency(), $baseCurrency))
                ->minorUnits;
        }

        return response()->json([
            'data' => AssetResource::collection($assets),
            'meta' => [
                'total_value' => [
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
            'kind' => ['required', Rule::in(Asset::KINDS)],
            'currency' => ['required', Rule::in(Currency::codes())],

            // Integer minor units. A float here would be a rounding bug waiting
            // to happen, so the API simply will not accept one.
            'purchase_price' => ['required', 'integer', 'min:0'],
            'purchase_date' => ['required', 'date'],
            'current_value' => ['nullable', 'integer', 'min:0'],
            'salvage_value' => ['nullable', 'integer', 'min:0', 'lte:purchase_price'],
            'depreciation_method' => ['nullable', Rule::in(Asset::DEPRECIATION_METHODS)],
            'depreciation_rate' => ['nullable', 'numeric', 'gt:0', 'lt:1'],
            'useful_life_years' => ['nullable', 'integer', 'min:1', 'max:200'],
            'insurance_provider' => ['nullable', 'string', 'max:120'],
            'insurance_expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $asset = new Asset;

        if (! empty($data['id'])) {
            $asset->id = $data['id'];
        }

        $asset->fill($data);
        $asset->depreciation_method = $data['depreciation_method'] ?? Asset::DEPRECIATION_NONE;
        $asset->salvage_value = $data['salvage_value'] ?? 0;
        $asset->save();

        return (new AssetResource($asset))->response()->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $asset = Asset::query()->findOrFail($id);

        return (new AssetResource($asset))->response();
    }

    public function depreciation(string $id, CalculateDepreciation $depreciation): JsonResponse
    {
        $asset = Asset::query()->findOrFail($id);
        $schedule = $depreciation->handle($asset);

        return response()->json([
            'data' => array_map(static fn (array $row): array => [
                'year' => $row['year'],
                'opening' => AssetResource::money($row['opening']),
                'depreciation' => AssetResource::money($row['depreciation']),
                'accumulated' => AssetResource::money($row['accumulated']),
                'closing' => AssetResource::money($row['closing']),
            ], $schedule),
            'meta' => [
                'method' => $asset->depreciation_method,
                'useful_life_years' => $asset->useful_life_years,
                'depreciable_base' => AssetResource::money($asset->depreciableBase()),
                'book_value' => AssetResource::money($depreciation->bookValueOn($asset)),
            ],
        ]);
    }
}
