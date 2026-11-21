<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

/*
| Auth web (Sanctum SPA): ambil cookie CSRF dulu lewat GET /sanctum/csrf-cookie.
| Auth mobile: POST /api/auth/token → Bearer token, DELETE /api/auth/token untuk mencabut.
*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Mobile: personal access token (Bearer). Limiter sama dengan login web.
    Route::post('token', [TokenController::class, 'store'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::delete('token', [TokenController::class, 'destroy']);
    });
});

// /api/households/{household}/... dengan scopeBindings menyusul di #10.
