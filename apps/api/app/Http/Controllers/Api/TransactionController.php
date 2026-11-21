<?php

namespace App\Http\Controllers\Api;

use App\Actions\Transactions\CreateTransaction;
use App\Actions\Transactions\DeleteTransaction;
use App\Actions\Transactions\UpdateTransaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\StoreTransactionRequest;
use App\Http\Requests\Transactions\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Tipis: semua logika saldo ada di Actions (bukan di controller).
 */
class TransactionController extends Controller
{
    public function store(StoreTransactionRequest $request, Household $household, CreateTransaction $createTransaction): JsonResponse
    {
        Gate::authorize('view', $household);

        /** @var User $user */
        $user = $request->user();

        [$transaction, $created] = $createTransaction->handle($household, $user, $request->toInput());

        // Retry dengan client_id yang sama → 200 + transaksi lama (Keputusan #11).
        return (new TransactionResource($transaction))->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(Household $household, Transaction $transaction): TransactionResource
    {
        Gate::authorize('view', $transaction);

        return new TransactionResource($transaction);
    }

    public function update(UpdateTransactionRequest $request, Household $household, Transaction $transaction, UpdateTransaction $updateTransaction): TransactionResource
    {
        Gate::authorize('update', $transaction);

        /** @var User $user */
        $user = $request->user();

        return new TransactionResource(
            $updateTransaction->handle($household, $user, $transaction, $request->toInput($transaction))
        );
    }

    public function destroy(Request $request, Household $household, Transaction $transaction, DeleteTransaction $deleteTransaction): Response
    {
        Gate::authorize('delete', $transaction);

        /** @var User $user */
        $user = $request->user();
        $deleteTransaction->handle($user, $transaction);

        return response()->noContent();
    }
}
