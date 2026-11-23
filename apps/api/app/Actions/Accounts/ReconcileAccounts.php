<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bukti, bukan harapan (Keputusan #3, ADR-002):
 *   balance == opening_balance + SUM(amount) WHERE deleted_at IS NULL
 * Selisih ≠ 0 berarti ada bug di jalur tulis. Bekerja lintas user (console/scheduler),
 * jadi memakai query builder, bukan model ber-scope.
 */
class ReconcileAccounts
{
    /**
     * @return Collection<int, AccountMismatch>
     */
    public function mismatches(?int $userId = null): Collection
    {
        $rows = DB::table('accounts as a')
            ->leftJoin('transactions as t', function ($join) {
                $join->on('t.account_id', '=', 'a.id')->whereNull('t.deleted_at');
            })
            ->when($userId !== null, fn ($q) => $q->where('a.user_id', $userId))
            ->groupBy('a.id', 'a.user_id', 'a.name', 'a.balance', 'a.opening_balance')
            ->havingRaw('a.balance <> a.opening_balance + COALESCE(SUM(t.amount), 0)')
            ->orderBy('a.id')
            ->get([
                'a.id as account_id', 'a.user_id', 'a.name', 'a.balance',
                DB::raw('a.opening_balance + COALESCE(SUM(t.amount), 0) as expected'),
            ]);

        return $rows->map(fn (object $r) => new AccountMismatch(
            accountId: (int) $r->account_id,
            userId: (int) $r->user_id,
            name: (string) $r->name,
            balance: (int) $r->balance,
            expected: (int) $r->expected,
        ))->values();
    }

    /**
     * Betulkan satu akun: kunci, hitung ulang di bawah kunci (bukan memakai angka lama),
     * lalu set balance = expected.
     */
    public function fix(int $accountId): int
    {
        return DB::transaction(function () use ($accountId) {
            /** @var Account $account */
            $account = Account::query()->withoutGlobalScopes()->whereKey($accountId)->lockForUpdate()->firstOrFail();

            $expected = $account->opening_balance + (int) Transaction::query()->withoutGlobalScopes()
                ->where('account_id', $accountId)
                ->whereNull('deleted_at')
                ->sum('amount');

            Account::query()->withoutGlobalScopes()->whereKey($accountId)->update(['balance' => $expected]);

            return $expected;
        });
    }
}
