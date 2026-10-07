<?php

use Illuminate\Support\Facades\Route;
use Workdo\Hrm\Http\Controllers\BranchController;
use Workdo\Hrm\Http\Controllers\DepartmentController;
use Workdo\Hrm\Http\Controllers\DesignationController;
use Workdo\Hrm\Http\Controllers\EmployeeDocumentTypeController;
use Workdo\Hrm\Http\Controllers\EmployeeController;
use Workdo\Hrm\Http\Controllers\EmployeeDocumentController;
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
    // <crud-routes>
});
