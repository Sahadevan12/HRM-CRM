<?php

use Illuminate\Support\Facades\Route;
use Workdo\Lead\Http\Controllers\SetupController;
use Workdo\Lead\Http\Controllers\LeadController;
// <use-statements>

Route::middleware(['web', 'auth', 'verified', 'PlanModuleCheck:Lead'])->group(function () {
    Route::prefix('crm/setup')->name('crm.setup.')->group(function () {
        Route::get('/', [SetupController::class, 'index'])->name('index');
        Route::prefix('{kind}')->where(['kind' => 'pipelines|lead-stages|deal-stages|labels|sources'])->group(function () {
            Route::post('/', [SetupController::class, 'store'])->name('store');
            Route::post('reorder', [SetupController::class, 'reorder'])->name('reorder');
            Route::put('{id}', [SetupController::class, 'update'])->whereNumber('id')->name('update');
            Route::delete('{id}', [SetupController::class, 'destroy'])->whereNumber('id')->name('destroy');
        });
    });
    Route::post('crm/leads/move', [LeadController::class, 'move'])->name('crm.leads.move');
    Route::resource('crm/leads', LeadController::class)->only(['index', 'store', 'update', 'destroy'])->names('crm.leads');
    // <crud-routes>
});
