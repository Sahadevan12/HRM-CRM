<?php

use Illuminate\Support\Facades\Route;
use Workdo\Account\Http\Controllers\ChartOfAccountController;
use Workdo\Account\Http\Controllers\JournalEntryController;
use Workdo\Account\Http\Controllers\PaymentController;
use Workdo\Account\Http\Controllers\ReportController;
// <use-statements>

Route::middleware(['web', 'auth', 'verified', 'PlanModuleCheck:Account'])->prefix('account')->group(function () {
    Route::resource('chart-of-accounts', ChartOfAccountController::class)->only(['index', 'store', 'update', 'destroy'])->names('account.chart-of-accounts');

    Route::resource('journal-entries', JournalEntryController::class)->only(['index', 'create', 'store', 'show', 'destroy'])
        ->parameters(['journal-entries' => 'journalEntry'])->names('account.journal-entries');

    // customer / vendor payments: one controller, the `kind` route default selects which
    foreach (['customer', 'vendor'] as $kind) {
        Route::get("{$kind}-payments", [PaymentController::class, 'index'])->defaults('kind', $kind)->name("account.{$kind}-payments.index");
        Route::post("{$kind}-payments", [PaymentController::class, 'store'])->defaults('kind', $kind)->name("account.{$kind}-payments.store");
        Route::delete("{$kind}-payments/{payment}", [PaymentController::class, 'destroy'])->defaults('kind', $kind)->name("account.{$kind}-payments.destroy")->whereNumber('payment');
    }

    Route::get('reports', [ReportController::class, 'index'])->name('account.reports.index');
    // <crud-routes>
});
