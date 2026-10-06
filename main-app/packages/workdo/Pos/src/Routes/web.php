<?php

use Illuminate\Support\Facades\Route;
use Workdo\Pos\Http\Controllers\PosController;
use Workdo\Pos\Http\Controllers\PosOrderController;
use Workdo\Pos\Http\Controllers\PosReportController;
// <use-statements>

Route::middleware(['web', 'auth', 'verified', 'PlanModuleCheck:Pos'])->prefix('pos')->group(function () {
    Route::get('/', [PosController::class, 'terminal'])->name('pos.terminal');
    Route::get('products', [PosController::class, 'products'])->name('pos.products');
    Route::post('checkout', [PosController::class, 'checkout'])->name('pos.checkout');
    Route::get('receipts/{sale}', [PosController::class, 'receipt'])->name('pos.receipts.show')->whereNumber('sale');

    Route::get('orders', [PosOrderController::class, 'index'])->name('pos.orders.index');
    Route::get('reports', [PosReportController::class, 'index'])->name('pos.reports.index');
    // <crud-routes>
});
