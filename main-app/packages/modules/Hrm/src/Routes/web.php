<?php

use Illuminate\Support\Facades\Route;
use Modules\Hrm\Http\Controllers\BranchController;
use Modules\Hrm\Http\Controllers\DepartmentController;
use Modules\Hrm\Http\Controllers\DesignationController;
use Modules\Hrm\Http\Controllers\EmployeeDocumentTypeController;
use Modules\Hrm\Http\Controllers\EmployeeController;
use Modules\Hrm\Http\Controllers\EmployeeDocumentController;
use Modules\Hrm\Http\Controllers\ShiftController;
use Modules\Hrm\Http\Controllers\HolidayController;
use Modules\Hrm\Http\Controllers\LeaveTypeController;
use Modules\Hrm\Http\Controllers\IpRestrictionController;
use Modules\Hrm\Http\Controllers\AttendanceController;
use Modules\Hrm\Http\Controllers\LeaveApplicationController;
use Modules\Hrm\Http\Controllers\HrmSettingsController;
use Modules\Hrm\Http\Controllers\SalaryComponentController;
use Modules\Hrm\Http\Controllers\PayrollController;
use Modules\Hrm\Http\Controllers\SalarySetupController;
use Modules\Hrm\Http\Controllers\LoanController;
use Modules\Hrm\Http\Controllers\AwardTypeController;
use Modules\Hrm\Http\Controllers\AwardController;
use Modules\Hrm\Http\Controllers\WarningController;
use Modules\Hrm\Http\Controllers\ComplaintController;
use Modules\Hrm\Http\Controllers\LifecycleController;
use Modules\Hrm\Http\Controllers\AnnouncementCategoryController;
use Modules\Hrm\Http\Controllers\EventTypeController;
use Modules\Hrm\Http\Controllers\AnnouncementController;
use Modules\Hrm\Http\Controllers\EventController;
use Modules\Hrm\Http\Controllers\HrmDocumentController;
use Modules\Hrm\Http\Controllers\HrmDashboardController;
use Modules\Hrm\Http\Controllers\SelfResignationController;
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
    Route::get('hrm/payslips/{payslip}/pdf', [PayrollController::class, 'pdf'])->name('hrm.payslips.pdf');
    Route::post('hrm/payrolls/{payroll}/approve', [PayrollController::class, 'approve'])->name('hrm.payrolls.approve');
    Route::post('hrm/payrolls/{payroll}/reopen', [PayrollController::class, 'reopen'])->name('hrm.payrolls.reopen');
    Route::post('hrm/payrolls/{payroll}/pay', [PayrollController::class, 'pay'])->name('hrm.payrolls.pay');
    Route::resource('hrm/payrolls', PayrollController::class)->only(['index', 'store', 'show', 'destroy'])->names('hrm.payrolls');
    Route::resource('hrm/award-types', AwardTypeController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.award-types');
    Route::resource('hrm/awards', AwardController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.awards');
    Route::resource('hrm/warnings', WarningController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.warnings');
    Route::resource('hrm/complaints', ComplaintController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.complaints');

    Route::prefix('hrm/lifecycle/{kind}')->where(['kind' => 'promotions|resignations|terminations|transfers'])->name('hrm.lifecycle.')->group(function () {
        Route::get('/', [LifecycleController::class, 'index'])->name('index');
        Route::post('/', [LifecycleController::class, 'store'])->name('store');
        Route::post('{id}/approve', [LifecycleController::class, 'approve'])->whereNumber('id')->name('approve');
        Route::post('{id}/reject', [LifecycleController::class, 'reject'])->whereNumber('id')->name('reject');
        Route::delete('{id}', [LifecycleController::class, 'destroy'])->whereNumber('id')->name('destroy');
    });
    Route::resource('hrm/announcement-categories', AnnouncementCategoryController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.announcement-categories');
    Route::resource('hrm/event-types', EventTypeController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.event-types');

    Route::get('hrm/dashboard', HrmDashboardController::class)->name('hrm.dashboard');

    Route::get('hrm/announcements/mine', [AnnouncementController::class, 'my'])->name('hrm.announcements.my');
    Route::post('hrm/announcements/{announcement}/acknowledge', [AnnouncementController::class, 'acknowledge'])->name('hrm.announcements.acknowledge');
    Route::resource('hrm/announcements', AnnouncementController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.announcements');

    Route::resource('hrm/events', EventController::class)->only(['index', 'store', 'update', 'destroy'])->names('hrm.events');

    Route::get('hrm/documents', [HrmDocumentController::class, 'index'])->name('hrm.documents.index');
    Route::post('hrm/documents', [HrmDocumentController::class, 'store'])->name('hrm.documents.store');
    Route::get('hrm/documents/{document}/download', [HrmDocumentController::class, 'download'])->name('hrm.documents.download');
    Route::post('hrm/documents/{document}/acknowledge', [HrmDocumentController::class, 'acknowledge'])->name('hrm.documents.acknowledge');
    Route::delete('hrm/documents/{document}', [HrmDocumentController::class, 'destroy'])->name('hrm.documents.destroy');
    Route::get('hrm/my-resignation', [SelfResignationController::class, 'index'])->name('hrm.resignation.my');
    Route::post('hrm/my-resignation', [SelfResignationController::class, 'store'])->name('hrm.resignation.store');
    Route::delete('hrm/my-resignation/{resignation}', [SelfResignationController::class, 'withdraw'])->whereNumber('resignation')->name('hrm.resignation.withdraw');
    // <crud-routes>
});
