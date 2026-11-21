<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\InvitationAcceptController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WalletController;
use App\Support\BudgetMonth;
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

// Penerima undangan: token dari link email. Pratinjau boleh tanpa login.
Route::get('invitations/{token}', [InvitationAcceptController::class, 'show'])->middleware('throttle:invitation-token');
Route::post('invitations/{token}/accept', [InvitationAcceptController::class, 'accept'])
    ->middleware(['auth:sanctum', 'throttle:invitation-token']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('households', [HouseholdController::class, 'index']);
    Route::post('households', [HouseholdController::class, 'store']);

    // Semua resource milik household ada di bawah /households/{household}/... dengan scoped binding,
    // supaya mis. /households/1/wallets/9 gagal (404) kalau wallet 9 bukan milik household 1.
    Route::prefix('households/{household}')->scopeBindings()->group(function () {
        Route::get('/', [HouseholdController::class, 'show']);

        Route::get('members', [MemberController::class, 'index']);
        Route::patch('members/{member}', [MemberController::class, 'update']);
        Route::delete('members/{member}', [MemberController::class, 'destroy']);

        Route::apiResource('wallets', WalletController::class);
        Route::post('wallets/{wallet}/archive', [WalletController::class, 'archive']);
        Route::post('wallets/{wallet}/unarchive', [WalletController::class, 'unarchive']);
        Route::post('wallets/{wallet}/adjust', [WalletController::class, 'adjust']);

        Route::apiResource('categories', CategoryController::class);
        Route::post('categories/{category}/archive', [CategoryController::class, 'archive']);
        Route::post('categories/{category}/unarchive', [CategoryController::class, 'unarchive']);

        // {month} = YYYY-MM (Keputusan #8: budget per kategori per bulan)
        Route::get('budgets/{month}', [BudgetController::class, 'show'])->where('month', BudgetMonth::ROUTE_PATTERN);
        Route::put('budgets/{month}', [BudgetController::class, 'update'])->where('month', BudgetMonth::ROUTE_PATTERN);
        Route::post('budgets/{month}/copy-from-previous', [BudgetController::class, 'copyFromPrevious'])->where('month', BudgetMonth::ROUTE_PATTERN);

        Route::apiResource('transactions', TransactionController::class);

        // {transfer} = transfer_group_id (UUID); dua baris berpasangan (Keputusan #4)
        Route::post('transfers', [TransferController::class, 'store']);
        Route::get('transfers/{transfer}', [TransferController::class, 'show'])->whereUuid('transfer');
        Route::patch('transfers/{transfer}', [TransferController::class, 'update'])->whereUuid('transfer');
        Route::delete('transfers/{transfer}', [TransferController::class, 'destroy'])->whereUuid('transfer');

        Route::get('invitations', [InvitationController::class, 'index']);
        Route::post('invitations', [InvitationController::class, 'store'])->middleware('throttle:invitations');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy']);
    });
});
