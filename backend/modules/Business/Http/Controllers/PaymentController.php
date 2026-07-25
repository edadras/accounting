<?php

declare(strict_types=1);

namespace Modules\Business\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Business\Actions\RecordInvoicePayment;
use Modules\Business\Http\Requests\StorePaymentRequest;
use Modules\Business\Http\Resources\PaymentResource;
use Modules\Business\Models\Invoice;
use Modules\Business\Models\Payment;

/**
 * @phpstan-import-type PaymentPayload from RecordInvoicePayment
 */
final class PaymentController
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if ($invoiceId = $request->query('invoice_id')) {
            $query->where('invoice_id', $invoiceId);
        }

        if ($contactId = $request->query('contact_id')) {
            $query->where('contact_id', $contactId);
        }

        if ($accountId = $request->query('account_id')) {
            $query->where('account_id', $accountId);
        }

        if ($from = $request->query('from')) {
            $query->where('paid_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('paid_at', '<=', $to);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => PaymentResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StorePaymentRequest $request, RecordInvoicePayment $record): JsonResponse
    {
        /** @var PaymentPayload $data */
        $data = $request->validated();

        $invoiceId = $request->string('invoice_id')->toString();

        // findOrFail, not the action's own lookup: a foreign invoice id must
        // answer 404 through the workspace scope before anything is recorded.
        Invoice::query()->findOrFail($invoiceId);

        $payment = $record->handle($invoiceId, $data);

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        return (new PaymentResource(Payment::query()->findOrFail($id)))->response();
    }
}
