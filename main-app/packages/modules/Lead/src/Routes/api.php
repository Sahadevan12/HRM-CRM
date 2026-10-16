<?php

use Illuminate\Support\Facades\Route;
use Modules\Lead\Http\Controllers\Api\CrmApiController;
use Modules\Lead\Http\Controllers\DealDetailController;
use Modules\Lead\Http\Controllers\LeadDetailController;

/*
| CRM part of the mobile API: /api/v1/crm/...   (see routes/api.php for the rules that apply to every call)
| The detail / child routes reuse the JSON controllers of the web drawer, so the permission checks cannot drift apart.
*/
Route::prefix('api/v1/crm')->name('api.crm.')->middleware(['api', 'auth:sanctum', 'api.access:Lead', 'throttle:120,1'])->group(function () {
    Route::get('pipelines', [CrmApiController::class, 'pipelines'])->name('pipelines');
    Route::get('dashboard', [CrmApiController::class, 'dashboard'])->name('dashboard');

    Route::get('leads', [CrmApiController::class, 'leads'])->name('leads.index');
    Route::post('leads', [CrmApiController::class, 'storeLead'])->name('leads.store');
    Route::post('leads/{lead}/move', [CrmApiController::class, 'moveLead'])->whereNumber('lead')->name('leads.move');

    Route::prefix('leads/{lead}')->whereNumber('lead')->name('leads.')->group(function () {
        Route::get('/', [LeadDetailController::class, 'show'])->name('show');
        Route::put('sync', [LeadDetailController::class, 'sync'])->name('sync');
        Route::post('items/{kind}', [LeadDetailController::class, 'add'])->whereIn('kind', ['task', 'call', 'email', 'discussion'])->name('items.add');
        Route::post('tasks/{task}/toggle', [LeadDetailController::class, 'toggleTask'])->whereNumber('task')->name('tasks.toggle');
        Route::delete('items/{kind}/{id}', [LeadDetailController::class, 'remove'])->whereIn('kind', ['task', 'call', 'email', 'discussion', 'file'])->whereNumber('id')->name('items.remove');
        Route::post('files', [LeadDetailController::class, 'upload'])->name('files.upload');
        Route::get('files/{file}', [LeadDetailController::class, 'download'])->whereNumber('file')->name('files.download');
    });

    Route::get('deals', [CrmApiController::class, 'deals'])->name('deals.index');
    Route::prefix('deals/{deal}')->whereNumber('deal')->name('deals.')->group(function () {
        Route::get('/', [DealDetailController::class, 'show'])->name('show');
        Route::post('status', [CrmApiController::class, 'dealStatus'])->name('status');
        Route::put('sync', [DealDetailController::class, 'sync'])->name('sync');
        Route::post('items/{kind}', [DealDetailController::class, 'add'])->whereIn('kind', ['task', 'call', 'email', 'discussion'])->name('items.add');
        Route::post('tasks/{task}/toggle', [DealDetailController::class, 'toggleTask'])->whereNumber('task')->name('tasks.toggle');
        Route::delete('items/{kind}/{id}', [DealDetailController::class, 'remove'])->whereIn('kind', ['task', 'call', 'email', 'discussion', 'file'])->whereNumber('id')->name('items.remove');
        Route::post('files', [DealDetailController::class, 'upload'])->name('files.upload');
        Route::get('files/{file}', [DealDetailController::class, 'download'])->whereNumber('file')->name('files.download');
    });
});
