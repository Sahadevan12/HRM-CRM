<?php

use Illuminate\Support\Facades\Route;
use Workdo\Hrm\Http\Controllers\BranchController;
use Workdo\Hrm\Http\Controllers\DepartmentController;
use Workdo\Hrm\Http\Controllers\DesignationController;
use Workdo\Hrm\Http\Controllers\EmployeeDocumentTypeController;
use Workdo\Hrm\Http\Controllers\EmployeeController;
use Workdo\Hrm\Http\Controllers\EmployeeDocumentController;
use Workdo\Hrm\Http\Controllers\ShiftController;
use Workdo\Hrm\Http\Controllers\HolidayController;
use Workdo\Hrm\Http\Controllers\LeaveTypeController;
use Workdo\Hrm\Http\Controllers\IpRestrictionController;
use Workdo\Hrm\Http\Controllers\AttendanceController;
use Workdo\Hrm\Http\Controllers\LeaveApplicationController;
use Workdo\Hrm\Http\Controllers\HrmSettingsController;
use Workdo\Hrm\Http\Controllers\SalaryComponentController;
use Workdo\Hrm\Http\Controllers\PayrollController;
use Workdo\Hrm\Http\Controllers\SalarySetupController;
use Workdo\Hrm\Http\Controllers\LoanController;
// <use-statements>

Route::middleware(['web', 'auth', 'verified', 'PlanModuleCheck:Hrm'])->group(function () {
    Route::resource('hrm/branches', BranchController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.branches');
    Route::resource('hrm/departments', DepartmentController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.departments');
    Route::resource('hrm/designations', DesignationController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.designations');
    Route::resource('hrm/employee-document-types', EmployeeDocumentTypeController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.employee-document-types');

    Route::resource('hrm/employees', EmployeeController::class)->names('hrm.employees');
    Route::post('hrm/employees/{employee}/documents', [EmployeeDocumentController::class, 'store'])->name('hrm.employees.documents.store');
    Route::get('hrm/employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'download'])->name('hrm.employees.documents.download');
    Route::delete('hrm/employees/{employee}/documents/{document}', [EmployeeDocumentController::class, 'destroy'])->name('hrm.employees.documents.destroy');
    Route::resource('hrm/shifts', ShiftController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.shifts');
    Route::resource('hrm/holidays', HolidayController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.holidays');
    Route::resource('hrm/leave-types', LeaveTypeController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.leave-types');
    Route::resource('hrm/ip-restrictions', IpRestrictionController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.ip-restrictions');
    Route::get('hrm/attendances/summary', [AttendanceController::class, 'summary'])->name('hrm.attendances.summary');
    Route::get('hrm/attendances/my', [AttendanceController::class, 'my'])->name('hrm.attendances.my');
    Route::post('hrm/attendances/clock-in', [AttendanceController::class, 'clockIn'])->name('hrm.attendances.clock-in');
    Route::post('hrm/attendances/clock-out', [AttendanceController::class, 'clockOut'])->name('hrm.attendances.clock-out');
    Route::resource('hrm/attendances', AttendanceController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.attendances');

    Route::get('hrm/leave-applications/balance', [LeaveApplicationController::class, 'balance'])->name('hrm.leave-applications.balance');
    Route::post('hrm/leave-applications/{leaveApplication}/approve', [LeaveApplicationController::class, 'approve'])->name('hrm.leave-applications.approve');
    Route::post('hrm/leave-applications/{leaveApplication}/reject', [LeaveApplicationController::class, 'reject'])->name('hrm.leave-applications.reject');
    Route::resource('hrm/leave-applications', LeaveApplicationController::class)->only(['index', 'store', 'destroy'])->names('hrm.leave-applications');

    Route::get('hrm/settings', [HrmSettingsController::class, 'edit'])->name('hrm.settings.edit');
    Route::put('hrm/settings', [HrmSettingsController::class, 'update'])->name('hrm.settings.update');
    Route::resource('hrm/salary-components', SalaryComponentController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.salary-components');

    Route::get('hrm/salary-setup', [SalarySetupController::class, 'index'])->name('hrm.salary-setup.index');
    Route::put('hrm/salary-setup/{employee}', [SalarySetupController::class, 'update'])->name('hrm.salary-setup.update');

    Route::resource('hrm/loans', LoanController::class)->only(['index', 'store', 'destroy'])->names('hrm.loans');
    Route::post('hrm/loans/{loan}/cancel', [LoanController::class, 'cancel'])->name('hrm.loans.cancel');

    Route::get('hrm/payslips', [PayrollController::class, 'my'])->name('hrm.payslips.my');
    Route::get('hrm/payslips/{payslip}', [PayrollController::class, 'payslip'])->name('hrm.payslips.show');
    Route::post('hrm/payrolls/{payroll}/approve', [PayrollController::class, 'approve'])->name('hrm.payrolls.approve');
    Route::post('hrm/payrolls/{payroll}/reopen', [PayrollController::class, 'reopen'])->name('hrm.payrolls.reopen');
    Route::post('hrm/payrolls/{payroll}/pay', [PayrollController::class, 'pay'])->name('hrm.payrolls.pay');
    Route::resource('hrm/payrolls', PayrollController::class)->only(['index', 'store', 'show', 'destroy'])->names('hrm.payrolls');
    // <crud-routes>
});
