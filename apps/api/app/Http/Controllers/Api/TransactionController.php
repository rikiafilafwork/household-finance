<?php

namespace App\Http\Controllers\Api;

use App\Actions\Transactions\CreateTransaction;
use App\Actions\Transactions\DeleteTransaction;
use App\Actions\Transactions\UpdateTransaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\ListTransactionsRequest;
use App\Http\Requests\Transactions\StoreTransactionRequest;
use App\Http\Requests\Transactions\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Models\User;
use App\Reports\TransactionQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Tipis: semua logika saldo ada di Actions (bukan di controller).
 */
class TransactionController extends Controller
{
    /**
     * Daftar transaksi user, terbaru dulu, cursor pagination
     * (occurred_on DESC, id DESC): stabil walau ada transaksi baru masuk saat paging.
     */
    public function index(ListTransactionsRequest $request): AnonymousResourceCollection
    {
        /** @var array{account_id?: int|string, category_id?: int|string, type?: string, from?: string, to?: string, q?: string} $filters */
        $filters = $request->safe()->except(['per_page', 'cursor']);

        $page = TransactionQuery::for($this->user($request), $filters)
            ->with(['category', 'savingsAllocation'])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 50))
            ->withQueryString();

        return TransactionResource::collection($page);
    }

    public function store(StoreTransactionRequest $request, CreateTransaction $createTransaction): JsonResponse
    {
        [$transaction, $created] = $createTransaction->handle($this->user($request), $request->toInput());

        // Retry dengan client_id yang sama → 200 + transaksi lama (Keputusan #15).
        return (new TransactionResource($transaction->load(['category', 'savingsAllocation'])))->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction->load(['category', 'savingsAllocation']));
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction, UpdateTransaction $updateTransaction): TransactionResource
    {
        return new TransactionResource(
            $updateTransaction->handle($this->user($request), $transaction, $request->toInput($transaction))->load(['category', 'savingsAllocation'])
        );
    }

    public function destroy(Request $request, Transaction $transaction, DeleteTransaction $deleteTransaction): Response
    {
        $deleteTransaction->handle($this->user($request), $transaction);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
