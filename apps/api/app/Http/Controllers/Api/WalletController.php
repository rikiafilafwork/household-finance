<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallets\AdjustBalance;
use App\Actions\Wallets\ArchiveWallet;
use App\Actions\Wallets\CreateWallet;
use App\Actions\Wallets\UpdateWallet;
use App\Enums\WalletType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallets\AdjustBalanceRequest;
use App\Http\Requests\Wallets\StoreWalletRequest;
use App\Http\Requests\Wallets\UpdateWalletRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\WalletResource;
use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;
use App\Reports\VisibleWallets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class WalletController extends Controller
{
    public function index(Request $request, Household $household): AnonymousResourceCollection
    {
        Gate::authorize('view', $household);

        /** @var User $user */
        $user = $request->user();

        $wallets = VisibleWallets::query($user, $household)
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('name')
            ->get();

        return WalletResource::collection($wallets);
    }

    public function store(StoreWalletRequest $request, Household $household, CreateWallet $createWallet): JsonResponse
    {
        Gate::authorize('create', [Wallet::class, $household, $request->visibility()]);

        /** @var User $user */
        $user = $request->user();

        $wallet = $createWallet->handle(
            $household,
            $user,
            $request->string('name')->toString(),
            WalletType::from($request->string('type')->toString()),
            $request->visibility(),
            $request->integer('opening_balance'),
        );

        return (new WalletResource($wallet))->response()->setStatusCode(201);
    }

    public function show(Household $household, Wallet $wallet): WalletResource
    {
        Gate::authorize('view', $wallet);

        return new WalletResource($wallet);
    }

    public function update(UpdateWalletRequest $request, Household $household, Wallet $wallet, UpdateWallet $updateWallet): WalletResource
    {
        Gate::authorize('view', $wallet);
        Gate::authorize('manage', $wallet);

        /** @var array{name?: string, type?: string, opening_balance?: int} $data */
        $data = $request->validated();

        return new WalletResource($updateWallet->handle($wallet, $data));
    }

    public function destroy(Household $household, Wallet $wallet, ArchiveWallet $archiveWallet): Response
    {
        Gate::authorize('view', $wallet);
        Gate::authorize('manage', $wallet);

        $archiveWallet->delete($wallet);

        return response()->noContent();
    }

    public function archive(Household $household, Wallet $wallet, ArchiveWallet $archiveWallet): WalletResource
    {
        Gate::authorize('view', $wallet);
        Gate::authorize('manage', $wallet);

        return new WalletResource($archiveWallet->archive($wallet));
    }

    public function unarchive(Household $household, Wallet $wallet, ArchiveWallet $archiveWallet): WalletResource
    {
        Gate::authorize('view', $wallet);
        Gate::authorize('manage', $wallet);

        return new WalletResource($archiveWallet->unarchive($wallet));
    }

    /**
     * Sesuaikan saldo ke angka riil → transaksi adjustment sebesar selisih (atau tidak ada kalau 0).
     */
    public function adjust(AdjustBalanceRequest $request, Household $household, Wallet $wallet, AdjustBalance $adjustBalance): JsonResponse
    {
        Gate::authorize('view', $wallet);
        Gate::authorize('manage', $wallet);

        /** @var User $user */
        $user = $request->user();

        [$wallet, $transaction] = $adjustBalance->handle(
            $user,
            $wallet,
            $request->integer('actual_balance'),
            $request->string('occurred_on')->toString(),
            $request->filled('note') ? $request->string('note')->toString() : null,
        );

        return response()->json([
            'data' => [
                'wallet' => new WalletResource($wallet),
                'adjustment' => $transaction ? new TransactionResource($transaction) : null,
            ],
        ], $transaction ? 201 : 200);
    }
}
