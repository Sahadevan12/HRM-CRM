<?php

use Illuminate\Support\Facades\Route;
use Workdo\Hrm\Http\Controllers\Api\HrmApiController;

/*
| HRM part of the mobile API: /api/v1/hrm/...   Employee self service only (see routes/api.php for the rules that apply to every call).
*/
Route::prefix('api/v1/hrm')->name('api.hrm.')->middleware(['api', 'auth:sanctum', 'api.access:Hrm', 'throttle:120,1'])->group(function () {
    Route::get('me', [HrmApiController::class, 'me'])->name('me');

    Route::get('attendance', [HrmApiController::class, 'attendance'])->name('attendance');
    Route::post('attendance/clock-in', [HrmApiController::class, 'clockIn'])->name('attendance.clock-in');
    Route::post('attendance/clock-out', [HrmApiController::class, 'clockOut'])->name('attendance.clock-out');

    Route::get('leaves', [HrmApiController::class, 'leaves'])->name('leaves.index');
    Route::post('leaves', [HrmApiController::class, 'applyLeave'])->name('leaves.store');
    Route::delete('leaves/{leave}', [HrmApiController::class, 'withdrawLeave'])->whereNumber('leave')->name('leaves.withdraw');

    Route::get('resignation', [HrmApiController::class, 'resignation'])->name('resignation.index');
    Route::post('resignation', [HrmApiController::class, 'resign'])->name('resignation.store');
    Route::delete('resignation/{resignation}', [HrmApiController::class, 'withdrawResignation'])->whereNumber('resignation')->name('resignation.withdraw');

    Route::get('payslips', [HrmApiController::class, 'payslips'])->name('payslips.index');
    Route::get('payslips/{payslip}', [HrmApiController::class, 'payslip'])->whereNumber('payslip')->name('payslips.show');
    Route::get('payslips/{payslip}/pdf', [HrmApiController::class, 'payslipPdf'])->whereNumber('payslip')->name('payslips.pdf');

    Route::get('announcements', [HrmApiController::class, 'announcements'])->name('announcements.index');
    Route::post('announcements/{announcement}/acknowledge', [HrmApiController::class, 'acknowledge'])->whereNumber('announcement')->name('announcements.acknowledge');
});
