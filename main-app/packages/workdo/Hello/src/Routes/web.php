<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['web'])->group(function () {
    Route::get('/hello-module', fn () => Inertia::render('Hello/Hello/Index', [
        'message' => 'Module engine works',
    ]))->name('hello.index');
});
