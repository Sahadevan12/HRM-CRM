<?php

use Illuminate\Support\Facades\Route;
use Workdo\Lead\Http\Controllers\SetupController;
use Workdo\Lead\Http\Controllers\LeadController;
use Workdo\Lead\Http\Controllers\LeadDetailController;
use Workdo\Lead\Http\Controllers\DealController;
use Workdo\Lead\Http\Controllers\DealDetailController;
use Workdo\Lead\Http\Controllers\CrmDashboardController;
use Workdo\Lead\Http\Controllers\AutomationController;
use Workdo\Lead\Http\Controllers\WebToLeadController;
// <use-statements>

// The public website form: no login and no CSRF token (the secret in the address and the throttle protect it).
Route::middleware(['web', 'throttle:30,1'])->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])->group(function () {
    Route::post('crm/web-to-lead/{token}', [WebToLeadController::class, 'store'])->name('crm.web-to-lead');
    Route::options('crm/web-to-lead/{token}', [WebToLeadController::class, 'preflight']);
});

Route::middleware(['web', 'auth', 'verified', 'PlanModuleCheck:Lead'])->group(function () {
    Route::prefix('crm/setup')->name('crm.setup.')->group(function () {
        Route::get('/', [SetupController::class, 'index'])->name('index');
        Route::put('automation', [AutomationController::class, 'update'])->name('automation.update');
        Route::post('automation/token', [AutomationController::class, 'regenerateToken'])->name('automation.token');
        Route::delete('automation/token', [AutomationController::class, 'disableToken'])->name('automation.token.disable');
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
    Route::get('crm/dashboard', [CrmDashboardController::class, 'dashboard'])->name('crm.dashboard');
    Route::get('crm/reports', [CrmDashboardController::class, 'reports'])->name('crm.reports');
    Route::post('crm/leads/{lead}/convert', [LeadController::class, 'convert'])->whereNumber('lead')->name('crm.leads.convert');

    Route::prefix('crm/deals/{deal}')->name('crm.deals.')->whereNumber('deal')->group(function () {
        Route::get('detail', [DealDetailController::class, 'show'])->name('detail');
        Route::put('sync', [DealDetailController::class, 'sync'])->name('sync');
        Route::post('items/{kind}', [DealDetailController::class, 'add'])->whereIn('kind', ['task', 'call', 'email', 'discussion'])->name('items.add');
        Route::post('tasks/{task}/toggle', [DealDetailController::class, 'toggleTask'])->whereNumber('task')->name('tasks.toggle');
        Route::delete('items/{kind}/{id}', [DealDetailController::class, 'remove'])->whereIn('kind', ['task', 'call', 'email', 'discussion', 'file'])->whereNumber('id')->name('items.remove');
        Route::post('files', [DealDetailController::class, 'upload'])->name('files.upload');
        Route::get('files/{file}', [DealDetailController::class, 'download'])->whereNumber('file')->name('files.download');
        Route::post('status', [DealController::class, 'status'])->name('status');
    });
    Route::post('crm/deals/move', [DealController::class, 'move'])->name('crm.deals.move');
    Route::resource('crm/deals', DealController::class)->only(['index', 'store', 'update', 'destroy'])->names('crm.deals');
    Route::post('crm/leads/move', [LeadController::class, 'move'])->name('crm.leads.move');
    Route::resource('crm/leads', LeadController::class)->only(['index', 'store', 'update', 'destroy'])->names('crm.leads');
    // <crud-routes>
});
