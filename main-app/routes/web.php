<?php

use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Users & roles (tenant-scoped)
    Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::patch('users/{user}/change-password', [UserController::class, 'changePassword'])->name('users.change-password');
    Route::resource('roles', RoleController::class)->only(['index', 'store', 'update', 'destroy']);

    // Settings (brand / system / currency) – stored per tenant
    Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('settings/brand', [SettingController::class, 'updateBrand'])->name('settings.brand.update');
    Route::post('settings/system', [SettingController::class, 'updateSystem'])->name('settings.system.update');
    Route::post('settings/currency', [SettingController::class, 'updateCurrency'])->name('settings.currency.update');

    Route::post('languages/change', [LanguageController::class, 'change'])->name('languages.change');
});

require __DIR__.'/auth.php';
