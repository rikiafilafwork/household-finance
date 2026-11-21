<?php

namespace App\Actions\Wallets;

use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bukti, bukan harapan (Keputusan #3, ADR-002):
 *   balance == opening_balance + SUM(amount) WHERE status = 'posted' AND deleted_at IS NULL
 * Selisih ≠ 0 berarti ada bug di jalur tulis.
 */
class ReconcileWallets
{
    public function __construct(private readonly LockWallets $lockWallets) {}

    /**
     * @return Collection<int, WalletMismatch>
     */
    public function mismatches(?int $householdId = null): Collection
    {
        $rows = DB::table('wallets as w')
            ->leftJoin('transactions as t', function ($join) {
                $join->on('t.wallet_id', '=', 'w.id')
                    ->where('t.status', '=', 'posted')
                    ->whereNull('t.deleted_at');
            })
            ->when($householdId !== null, fn ($q) => $q->where('w.household_id', $householdId))
            ->groupBy('w.id', 'w.household_id', 'w.name', 'w.balance', 'w.opening_balance')
            ->havingRaw('w.balance <> w.opening_balance + COALESCE(SUM(t.amount), 0)')
            ->orderBy('w.id')
            ->get([
                'w.id as wallet_id', 'w.household_id', 'w.name', 'w.balance',
                DB::raw('w.opening_balance + COALESCE(SUM(t.amount), 0) as expected'),
            ]);

        return $rows->map(fn (object $r) => new WalletMismatch(
            walletId: (int) $r->wallet_id,
            householdId: (int) $r->household_id,
            name: (string) $r->name,
            balance: (int) $r->balance,
            expected: (int) $r->expected,
        ))->values();
    }

    /**
     * Betulkan satu wallet: kunci, hitung ulang di bawah kunci (bukan memakai angka lama),
     * lalu set balance = expected.
     */
    public function fix(int $walletId): int
    {
        return DB::transaction(function () use ($walletId) {
            $wallet = $this->lockWallets->one($walletId);

            $expected = $wallet->opening_balance + (int) $wallet->transactions()
                ->where('status', 'posted')
                ->sum('amount');

            Wallet::query()->whereKey($walletId)->update(['balance' => $expected]);

            return $expected;
        });
    }
}
