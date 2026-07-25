<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Payroll\Http\Controllers\EmployeeController;
use Modules\Payroll\Http\Controllers\PayrollRunController;
use Modules\Payroll\Http\Controllers\PayslipController;
use Modules\Payroll\Http\Controllers\TaxRuleController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::post('employees', [EmployeeController::class, 'store']);
        Route::get('employees/{id}', [EmployeeController::class, 'show']);
        Route::patch('employees/{id}', [EmployeeController::class, 'update']);
        Route::post('employees/{id}/compensation', [EmployeeController::class, 'storeCompensation']);

        Route::get('payroll/tax-rules', [TaxRuleController::class, 'index']);
        Route::post('payroll/tax-rules', [TaxRuleController::class, 'store']);

        Route::get('payroll/runs', [PayrollRunController::class, 'index']);
        Route::post('payroll/runs', [PayrollRunController::class, 'store']);
        Route::get('payroll/runs/{id}', [PayrollRunController::class, 'show']);
        Route::post('payroll/runs/{id}/approve', [PayrollRunController::class, 'approve']);
        Route::post('payroll/runs/{id}/pay', [PayrollRunController::class, 'pay']);
        Route::delete('payroll/runs/{id}', [PayrollRunController::class, 'destroy']);

        Route::get('payroll/payslips/{id}', [PayslipController::class, 'show']);
    });
