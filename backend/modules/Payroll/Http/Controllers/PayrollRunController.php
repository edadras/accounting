<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\WorkspaceMember;
use Modules\Payroll\Actions\ApprovePayrollRun;
use Modules\Payroll\Actions\CreatePayrollRun;
use Modules\Payroll\Actions\DiscardPayrollRun;
use Modules\Payroll\Actions\MarkPayrollRunPaid;
use Modules\Payroll\Http\Requests\StorePayrollRunRequest;
use Modules\Payroll\Http\Resources\PayrollRunResource;
use Modules\Payroll\Models\PayrollRun;

final class PayrollRunController
{
    public function index(Request $request): JsonResponse
    {
        $query = PayrollRun::query()
            ->withCount('payslips')
            ->orderByDesc('period_start')
            ->orderByDesc('id');

        if (is_string($status = $request->query('status'))) {
            $query->ofStatus($status);
        }

        if (is_string($from = $request->query('from'))) {
            $query->whereDate('period_end', '>=', $from);
        }

        if (is_string($to = $request->query('to'))) {
            $query->whereDate('period_start', '<=', $to);
        }

        $perPage = min((int) $request->query('per_page', 50), 200);
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => PayrollRunResource::collection($page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StorePayrollRunRequest $request, CreatePayrollRun $create): JsonResponse
    {
        /** @var array{period_start:string,period_end:string,account_id:string} $data */
        $data = $request->validated();

        $run = $create->handle($data);

        return (new PayrollRunResource($run->load(['payslips.lines', 'payslips.employee'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): JsonResponse
    {
        $run = PayrollRun::query()
            ->with(['payslips.lines', 'payslips.employee'])
            ->findOrFail($id);

        return (new PayrollRunResource($run))->response();
    }

    public function approve(Request $request, string $id, ApprovePayrollRun $approve): JsonResponse
    {
        $this->assertMayWrite($request);

        // Confirm the run is visible in this workspace before the action acts
        // on it, so a foreign id answers 404 rather than a domain error.
        PayrollRun::query()->findOrFail($id);

        $run = $approve->handle($id, $request->user());

        return (new PayrollRunResource($run->load(['payslips.lines', 'payslips.employee'])))->response();
    }

    public function pay(Request $request, string $id, MarkPayrollRunPaid $pay): JsonResponse
    {
        $this->assertMayWrite($request);

        PayrollRun::query()->findOrFail($id);

        $paidAt = $request->input('paid_at');

        $run = $pay->handle($id, is_string($paidAt) ? $paidAt : null);

        return (new PayrollRunResource($run->load(['payslips.lines', 'payslips.employee'])))->response();
    }

    public function destroy(Request $request, string $id, DiscardPayrollRun $discard): JsonResponse
    {
        $this->assertMayWrite($request);

        PayrollRun::query()->findOrFail($id);

        $discard->handle($id);

        return response()->json(null, 204);
    }

    private function assertMayWrite(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);
    }
}
