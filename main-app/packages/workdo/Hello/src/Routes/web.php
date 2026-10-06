<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Only reachable when the Hello module is enabled platform-wide AND included in the company's plan.
Route::middleware(['web', 'auth', 'verified', 'PlanModuleCheck:Hello'])->group(function () {
    Route::get('/hello-module', fn () => Inertia::render('Hello/Hello/Index', [
        'message' => 'Module engine works',
    ]))->name('hello.index');
});
