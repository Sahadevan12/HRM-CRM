<?php

use Illuminate\Support\Facades\Route;
use Workdo\SalesPurchase\Http\Controllers\DocumentController;
use Workdo\SalesPurchase\Support\DocumentType;
// <use-statements>

Route::middleware(['web', 'auth', 'verified', 'PlanModuleCheck:SalesPurchase'])->prefix('sales-purchase')->group(function () {
    // The five trade documents share one controller; the `type` route default selects the document.
    foreach (DocumentType::all() as $type => $meta) {
        $slug = $meta['slug'];
        $name = "salespurchase.{$slug}";
        $default = ['type' => $type];

        Route::get($slug, [DocumentController::class, 'index'])->defaults('type', $type)->name("{$name}.index");
        Route::get("{$slug}/create", [DocumentController::class, 'create'])->defaults('type', $type)->name("{$name}.create");
        Route::post($slug, [DocumentController::class, 'store'])->defaults('type', $type)->name("{$name}.store");
        Route::get("{$slug}/{document}", [DocumentController::class, 'show'])->defaults('type', $type)->name("{$name}.show")->whereNumber('document');
        Route::get("{$slug}/{document}/edit", [DocumentController::class, 'edit'])->defaults('type', $type)->name("{$name}.edit")->whereNumber('document');
        Route::put("{$slug}/{document}", [DocumentController::class, 'update'])->defaults('type', $type)->name("{$name}.update")->whereNumber('document');
        Route::delete("{$slug}/{document}", [DocumentController::class, 'destroy'])->defaults('type', $type)->name("{$name}.destroy")->whereNumber('document');

        // state transitions that exist for this document type
        $actions = match (true) {
            $meta['is_return'] => ['approve', 'complete'],
            $type === DocumentType::SALES_PROPOSAL => ['send', 'accept', 'reject', 'convert'],
            default => ['post'],
        };
        foreach ($actions as $action) {
            Route::post("{$slug}/{document}/{$action}", [DocumentController::class, $action])->defaults('type', $type)->name("{$name}.{$action}")->whereNumber('document');
        }
    }
    // <crud-routes>
});
