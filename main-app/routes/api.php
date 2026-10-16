<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
| Mobile API, version 1: /api/v1/...   (JSON in, JSON out; every call except login needs the header  Authorization: Bearer <token>)
|
| Core (this file): login / me / logout. Every module adds its own routes in packages/workdo/<Module>/src/Routes/api.php, behind
| `auth:sanctum` + `api.access:<Module>` (account enabled, plan not expired, module active), and checks the same permissions as the web app.
*/
Route::prefix('v1')->name('api.')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('auth.login');

    Route::middleware(['auth:sanctum', 'api.access', 'throttle:120,1'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});
