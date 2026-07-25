<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Payroll\Http\Resources\PayslipResource;
use Modules\Payroll\Models\Payslip;

final class PayslipController
{
    public function show(string $id): JsonResponse
    {
        $payslip = Payslip::query()
            ->with(['lines', 'employee', 'run'])
            ->findOrFail($id);

        // Reading a payslip is also the cheapest opportunity to notice that it
        // has stopped adding up, so it is taken.
        $payslip->assertReconciles();

        return (new PayslipResource($payslip))->response();
    }
}
