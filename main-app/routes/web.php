<?php

use App\Http\Controllers\BankTransferPaymentController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PlanController;
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

Route::middleware(['auth', 'verified', 'PlanModuleCheck'])->group(function () {
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
    Route::post('settings/payment', [SettingController::class, 'updatePayment'])->name('settings.payment.update');

    // Plans / subscriptions
    Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
    Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
    Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');
    Route::get('plans/{plan}/subscribe', [PlanController::class, 'subscribe'])->name('plans.subscribe');
    Route::post('plans/apply-coupon', [PlanController::class, 'applyCoupon'])->name('plans.apply-coupon');
    Route::post('plans/{plan}/assign-free', [PlanController::class, 'assignFree'])->name('plans.assign-free');
    Route::post('plans/{plan}/start-trial', [PlanController::class, 'startTrial'])->name('plans.start-trial');

    Route::resource('coupons', CouponController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');

    // Bank transfer payments
    Route::get('bank-transfers', [BankTransferPaymentController::class, 'index'])->name('bank-transfers.index');
    Route::post('bank-transfers', [BankTransferPaymentController::class, 'store'])->name('bank-transfers.store');
    Route::post('bank-transfers/{payment}/approve', [BankTransferPaymentController::class, 'approve'])->name('bank-transfers.approve');
    Route::post('bank-transfers/{payment}/reject', [BankTransferPaymentController::class, 'reject'])->name('bank-transfers.reject');
    Route::get('bank-transfers/{payment}/attachment', [BankTransferPaymentController::class, 'attachment'])->name('bank-transfers.attachment');

    // Add-on modules (superadmin)
    Route::get('add-ons', [ModuleController::class, 'index'])->name('add-ons.index');
    Route::post('add-ons/{module}/toggle', [ModuleController::class, 'toggle'])->name('add-ons.toggle');

    Route::post('languages/change', [LanguageController::class, 'change'])->name('languages.change');
});

require __DIR__.'/auth.php';
