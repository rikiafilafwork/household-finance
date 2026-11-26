<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlanTemplateController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SavingsController;
use App\Http\Controllers\Api\SavingsGoalController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransferController;
use App\Plan\PlanMonth;
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
        Route::patch('me', [ProfileController::class, 'update']);
        Route::put('password', [ProfileController::class, 'password'])->middleware('throttle:login');
        Route::delete('token', [TokenController::class, 'destroy']);
    });
});

/*
| Semua data milik user login (Keputusan #5). Route model binding memakai global scope
| BelongsToUser, jadi id milik user lain = 404, bukan 403 (tidak membocorkan keberadaannya).
*/
Route::middleware('auth:sanctum')->group(function () {
    // Beranda (Keputusan #11): angka rencana vs aktual dari PlanComparisonQuery yang sama dengan Rencana.
    Route::get('dashboard', DashboardController::class);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification');

    Route::apiResource('accounts', AccountController::class);
    Route::post('accounts/{account}/archive', [AccountController::class, 'archive']);
    Route::post('accounts/{account}/unarchive', [AccountController::class, 'unarchive']);
    Route::post('accounts/{account}/adjust', [AccountController::class, 'adjust']);

    // Tabungan dengan tujuan: ringkasan per rekening tabungan + CRUD tujuan.
    Route::get('savings', SavingsController::class);
    Route::apiResource('savings-goals', SavingsGoalController::class)->parameters(['savings-goals' => 'savingsGoal'])->except('index');
    Route::post('savings-goals/{savingsGoal}/archive', [SavingsGoalController::class, 'archive']);
    Route::post('savings-goals/{savingsGoal}/unarchive', [SavingsGoalController::class, 'unarchive']);
    Route::post('savings-goals/{savingsGoal}/achieve', [SavingsGoalController::class, 'achieve']);
    Route::post('savings-goals/{savingsGoal}/unachieve', [SavingsGoalController::class, 'unachieve']);
    Route::get('savings-goals/{savingsGoal}/allocations', [SavingsGoalController::class, 'allocations']);
    Route::post('savings-goals/{savingsGoal}/allocations', [SavingsGoalController::class, 'allocate']);
    Route::post('savings-goals/{savingsGoal}/move', [SavingsGoalController::class, 'move']);

    Route::apiResource('categories', CategoryController::class);
    Route::post('categories/{category}/archive', [CategoryController::class, 'archive']);
    Route::post('categories/{category}/unarchive', [CategoryController::class, 'unarchive']);

    Route::apiResource('transactions', TransactionController::class);

    // {transfer} = transfer_group_id (UUID); dua baris berpasangan (Keputusan #4)
    Route::post('transfers', [TransferController::class, 'store']);
    Route::get('transfers/{transfer}', [TransferController::class, 'show'])->whereUuid('transfer');
    Route::patch('transfers/{transfer}', [TransferController::class, 'update'])->whereUuid('transfer');
    Route::delete('transfers/{transfer}', [TransferController::class, 'destroy'])->whereUuid('transfer');

    // Rencana bulanan (Keputusan #7). {month} = YYYY-MM.
    Route::get('plan/{month}', [PlanController::class, 'index'])->where('month', PlanMonth::ROUTE_PATTERN);
    Route::post('plan/{month}/items', [PlanController::class, 'store'])->where('month', PlanMonth::ROUTE_PATTERN);
    Route::post('plan/{month}/copy-from-previous', [PlanController::class, 'copyFromPrevious'])->where('month', PlanMonth::ROUTE_PATTERN);
    Route::post('plan/{month}/generate', [PlanController::class, 'generate'])->where('month', PlanMonth::ROUTE_PATTERN);
    Route::get('plan-items/{planItem}', [PlanController::class, 'show']);
    Route::patch('plan-items/{planItem}', [PlanController::class, 'update']);
    Route::delete('plan-items/{planItem}', [PlanController::class, 'destroy']);
    Route::post('plan-items/{planItem}/skip', [PlanController::class, 'skip']);
    Route::post('plan-items/{planItem}/unskip', [PlanController::class, 'unskip']);
    // Centang = transaksi (Keputusan #8); client_id wajib (Keputusan #15).
    Route::post('plan-items/{planItem}/check', [PlanController::class, 'check']);
    Route::post('plan-items/{planItem}/uncheck', [PlanController::class, 'uncheck']);

    // Template bulanan (Keputusan #9). Mengubah template tidak menyentuh item yang sudah dibuat.
    Route::apiResource('plan-templates', PlanTemplateController::class)->parameters(['plan-templates' => 'planTemplate']);

    // Import CSV mutasi rekening (Keputusan #13): upload → 202, poll status, lalu baca baris preview.
    Route::get('imports', [ImportController::class, 'index']);
    Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:imports');
    Route::get('imports/{import}', [ImportController::class, 'show']);
    Route::get('imports/{import}/rows', [ImportController::class, 'rows']);
    Route::post('imports/{import}/commit', [ImportController::class, 'commit']);

    // Laporan (Minggu 7): angka rencana vs aktual dari PlanComparisonQuery, sama dengan Beranda.
    Route::prefix('reports')->group(function () {
        Route::get('summary', [ReportController::class, 'summary']);
        Route::get('by-category', [ReportController::class, 'byCategory']);
        Route::get('trend', [ReportController::class, 'trend']);
        Route::get('cash-flow', [ReportController::class, 'cashFlow']);
        Route::get('cash-vs-noncash', [ReportController::class, 'cashVsNonCash']);
        Route::get('export.csv', [ReportController::class, 'export'])->middleware('throttle:exports');
    });
});
