<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Billing\Http\Resources\SubscriptionInvoiceResource;
use Modules\Billing\Models\SubscriptionInvoice;

final class InvoiceController
{
    public function index(): JsonResponse
    {
        $invoices = SubscriptionInvoice::query()->orderByDesc('issued_at')->orderByDesc('id')->get();

        return response()->json(['data' => SubscriptionInvoiceResource::collection($invoices)]);
    }
}
