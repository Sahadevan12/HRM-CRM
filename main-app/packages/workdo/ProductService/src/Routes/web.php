<?php

use Illuminate\Support\Facades\Route;
use Workdo\ProductService\Http\Controllers\ProductCategoryController;
use Workdo\ProductService\Http\Controllers\ProductController;
use Workdo\ProductService\Http\Controllers\ProductTaxController;
use Workdo\ProductService\Http\Controllers\ProductUnitController;
use Workdo\ProductService\Http\Controllers\StockTransferController;
use Workdo\ProductService\Http\Controllers\WarehouseController;
// <use-statements>

Route::middleware(['web', 'auth', 'verified', 'PlanModuleCheck:ProductService'])->group(function () {
    Route::resource('product-service/product-categories', ProductCategoryController::class)->only(['index', 'store', 'update', 'destroy'])->names('productservice.product-categories');
    Route::resource('product-service/product-units', ProductUnitController::class)->only(['index', 'store', 'update', 'destroy'])->names('productservice.product-units');
    Route::resource('product-service/product-taxes', ProductTaxController::class)->only(['index', 'store', 'update', 'destroy'])->names('productservice.product-taxes');
    Route::resource('product-service/warehouses', WarehouseController::class)->only(['index', 'store', 'update', 'destroy'])->names('productservice.warehouses');

    Route::resource('product-service/products', ProductController::class)->only(['index', 'store', 'update', 'destroy'])->names('productservice.products');
    Route::get('product-service/products/{product}/stock', [ProductController::class, 'stock'])->name('productservice.products.stock');
    Route::post('product-service/products/{product}/stock', [ProductController::class, 'updateStock'])->name('productservice.products.update-stock');

    Route::resource('product-service/stock-transfers', StockTransferController::class)->only(['index', 'store', 'destroy'])
        ->parameters(['stock-transfers' => 'transfer'])->names('productservice.stock-transfers');
    // <crud-routes>
});
