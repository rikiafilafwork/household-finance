<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\User;
use App\Notifications\SavingsOverdrawn;
use App\Queries\SavingsQuery;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rem "tabungan terpakai": setelah saldo rekening tabungan berubah (atau alokasinya dikurangi) dan
 * transaksi DB commit, hitung ulang terpakai lewat SavingsQuery. Masuk kondisi → flag
 * savings_overdrawn_at diisi secara atomik (WHERE NULL), hanya pemenang yang mengirim notifikasi →
 * tidak dobel. Sehat lagi → flag di-reset tanpa notifikasi, supaya kejadian berikutnya diberi tahu lagi.
 */
class CheckSavingsOverdrawn implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $accountId) {}

    public function handle(): void
    {
        // Dari worker tidak ada user login → global scope BelongsToUser dilewati, lalu ownedBy.
        $account = Account::query()->withoutGlobalScopes()->find($this->accountId);
        $user = $account !== null ? User::find($account->user_id) : null;
        if ($account === null || $user === null) {
            return;
        }

        // Rekening arsip / bukan tabungan tidak muncul di SavingsQuery → dianggap tidak terpakai.
        $row = collect(SavingsQuery::for($user)['accounts'])
            ->first(fn (array $r) => $r['account']->getKey() === $account->getKey());
        $overdrawn = $row['overdrawn'] ?? 0;

        $flag = Account::query()->withoutGlobalScopes()->whereKey($account->getKey());

        if ($overdrawn === 0) {
            $flag->whereNotNull('savings_overdrawn_at')->update(['savings_overdrawn_at' => null]);

            return;
        }

        if ($flag->whereNull('savings_overdrawn_at')->update(['savings_overdrawn_at' => now()]) === 1) {
            /** @var array{account: Account, balance: int, allocated: int, overdrawn: int} $row */
            $user->notify(new SavingsOverdrawn($row['account'], $row['balance'], $row['allocated'], $overdrawn));
        }
    }
}
