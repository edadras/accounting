<?php

declare(strict_types=1);

namespace Modules\Business\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Business\Actions\CreateInvoice;
use Modules\Business\Actions\RecordInvoicePayment;
use Modules\Business\Actions\VoidInvoice;
use Modules\Business\Http\Requests\StoreInvoiceRequest;
use Modules\Business\Http\Requests\StorePaymentRequest;
use Modules\Business\Http\Resources\InvoiceResource;
use Modules\Business\Http\Resources\PaymentResource;
use Modules\Business\Models\Invoice;
use Modules\Core\Models\WorkspaceMember;

/**
 * @phpstan-import-type InvoicePayload from CreateInvoice
 * @phpstan-import-type PaymentPayload from RecordInvoicePayment
 */
final class InvoiceController
{
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query()
            ->with(['contact:id,name,type', 'project:id,name,status'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id');

        if ($direction = $request->query('direction')) {
            $query->ofDirection((string) $direction);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($contactId = $request->query('contact_id')) {
            $query->where('contact_id', $contactId);
        }

        if ($projectId = $request->query('project_id')) {
            $query->where('project_id', $projectId);
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        if ($from = $request->query('from')) {
            $query->where('issue_date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('issue_date', '<=', $to);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => InvoiceResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StoreInvoiceRequest $request, CreateInvoice $create): JsonResponse
    {
        /** @var InvoicePayload $payload */
        $payload = $request->validated();

        $invoice = $create->handle($payload);

        return (new InvoiceResource($invoice->load(['items', 'contact', 'project'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $invoice = Invoice::query()
            ->with(['items', 'contact', 'project', 'payments'])
            ->findOrFail($id);

        return (new InvoiceResource($invoice))->response();
    }

    public function void(Request $request, string $id, VoidInvoice $void): JsonResponse
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);

        // Confirm the invoice is visible in this workspace before the action
        // reports on it, so a foreign id answers 404 rather than a domain error.
        Invoice::query()->findOrFail($id);

        $invoice = $void->handle($id, $request->input('reason'));

        return (new InvoiceResource($invoice->load(['items', 'contact', 'project'])))->response();
    }

    public function pay(StorePaymentRequest $request, string $id, RecordInvoicePayment $record): JsonResponse
    {
        Invoice::query()->findOrFail($id);

        /** @var PaymentPayload $payload */
        $payload = $request->validated();

        $payment = $record->handle($id, $payload);

        // Re-read the invoice instead of reaching through the payment: the
        // payment has just moved its status, and that is what the client needs
        // back in the same response.
        $invoice = Invoice::query()->with('items')->findOrFail($id);

        return (new PaymentResource($payment))
            ->additional(['meta' => [
                'invoice' => new InvoiceResource($invoice),
            ]])
            ->response()
            ->setStatusCode(201);
    }
}
