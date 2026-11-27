<?php

namespace App\Http\Controllers\Api;

use App\Actions\Accounts\AdjustBalance;
use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\UpdateAccount;
use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\AdjustBalanceRequest;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Http\Resources\TransactionResource;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Akun milik user login. Route model binding `{account}` memakai global scope BelongsToUser,
 * jadi akun user lain = 404 (Keputusan #5).
 */
class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['include_archived' => ['sometimes', 'boolean']]);

        $accounts = Account::query()
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return AccountResource::collection($accounts);
    }

    public function store(StoreAccountRequest $request, CreateAccount $createAccount): JsonResponse
    {
        $account = $createAccount->handle(
            $this->user($request),
            $request->string('name')->toString(),
            AccountType::from($request->string('type')->toString()),
            $request->integer('opening_balance'),
            $request->has('sort_order') ? $request->integer('sort_order') : null,
            $request->boolean('is_savings'),
        );

        return (new AccountResource($account))->response()->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account);
    }

    public function update(UpdateAccountRequest $request, Account $account, UpdateAccount $updateAccount): AccountResource
    {
        /** @var array{name?: string, type?: string, is_savings?: bool, opening_balance?: int, sort_order?: int} $data */
        $data = $request->validated();

        return new AccountResource($updateAccount->handle($account, $data));
    }

    public function destroy(Account $account, ArchiveAccount $archiveAccount): Response
    {
        $archiveAccount->delete($account);

        return response()->noContent();
    }

    public function archive(Account $account, ArchiveAccount $archiveAccount): AccountResource
    {
        return new AccountResource($archiveAccount->archive($account));
    }

    public function unarchive(Account $account, ArchiveAccount $archiveAccount): AccountResource
    {
        return new AccountResource($archiveAccount->unarchive($account));
    }

    /**
     * Sesuaikan saldo ke angka riil → transaksi adjustment sebesar selisih (atau tidak ada kalau 0).
     */
    public function adjust(AdjustBalanceRequest $request, Account $account, AdjustBalance $adjustBalance): JsonResponse
    {
        [$account, $transaction] = $adjustBalance->handle(
            $this->user($request),
            $account,
            $request->integer('actual_balance'),
            $request->string('occurred_on')->toString(),
            $request->filled('note') ? $request->string('note')->toString() : null,
        );

        return response()->json([
            'data' => [
                'account' => new AccountResource($account),
                'adjustment' => $transaction ? new TransactionResource($transaction) : null,
            ],
        ], $transaction ? 201 : 200);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
