<?php

use Illuminate\Support\Facades\Route;
use Workdo\Lead\Http\Controllers\SetupController;
use Workdo\Lead\Http\Controllers\LeadController;
use Workdo\Lead\Http\Controllers\LeadDetailController;
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
    Route::prefix('crm/leads/{lead}')->name('crm.leads.')->whereNumber('lead')->group(function () {
        Route::get('detail', [LeadDetailController::class, 'show'])->name('detail');
        Route::put('sync', [LeadDetailController::class, 'sync'])->name('sync');
        Route::post('items/{kind}', [LeadDetailController::class, 'add'])->whereIn('kind', ['task', 'call', 'email', 'discussion'])->name('items.add');
        Route::post('tasks/{task}/toggle', [LeadDetailController::class, 'toggleTask'])->whereNumber('task')->name('tasks.toggle');
        Route::delete('items/{kind}/{id}', [LeadDetailController::class, 'remove'])->whereIn('kind', ['task', 'call', 'email', 'discussion', 'file'])->whereNumber('id')->name('items.remove');
        Route::post('files', [LeadDetailController::class, 'upload'])->name('files.upload');
        Route::get('files/{file}', [LeadDetailController::class, 'download'])->whereNumber('file')->name('files.download');
    });
    Route::post('crm/leads/move', [LeadController::class, 'move'])->name('crm.leads.move');
    Route::resource('crm/leads', LeadController::class)->only(['index', 'store', 'update', 'destroy'])->names('crm.leads');
    // <crud-routes>
});
