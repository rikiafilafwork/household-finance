<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\SavingsGoal;
use Illuminate\Validation\ValidationException;

class ArchiveAccount
{
    public function archive(Account $account): Account
    {
        self::ensureHoldsNoGoalMoney($account, 'account');

        $account->update(['archived_at' => $account->archived_at ?? now()]);

        return $account;
    }

    /**
     * Rekening tabungan tidak boleh dinonaktifkan/diarsip selagi tujuan di dalamnya masih memegang uang.
     */
    public static function ensureHoldsNoGoalMoney(Account $account, string $field): void
    {
        $holdsMoney = SavingsGoal::ownedBy($account->user_id)
            ->where('account_id', $account->getKey())
            ->where('allocated_amount', '>', 0)
            ->exists();

        if ($holdsMoney) {
            throw ValidationException::withMessages([
                $field => 'Rekening ini masih memegang uang tujuan tabungan. Kosongkan atau pindahkan alokasinya dulu.',
            ]);
        }
    }

    public function unarchive(Account $account): Account
    {
        $account->update(['archived_at' => null]);

        return $account;
    }

    /**
     * Hapus permanen hanya kalau belum pernah ada transaksi (termasuk yang soft-deleted,
     * karena FK & riwayat audit). Selain itu wajib arsip.
     */
    public function delete(Account $account): void
    {
        if ($account->transactions()->withoutGlobalScopes()->exists()) {
            throw ValidationException::withMessages([
                'account' => 'Rekening ini sudah punya transaksi. Arsipkan saja supaya riwayatnya tetap utuh.',
            ]);
        }

        // Tujuan (termasuk yang diarsip) menahan rekeningnya lewat FK; tolak rapi, bukan 500.
        if (SavingsGoal::ownedBy($account->user_id)->where('account_id', $account->getKey())->exists()) {
            throw ValidationException::withMessages([
                'account' => 'Rekening ini punya tujuan tabungan. Arsipkan saja, atau hapus tujuannya dulu.',
            ]);
        }

        $account->delete();
    }
}
