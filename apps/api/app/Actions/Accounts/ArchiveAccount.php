<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use Illuminate\Validation\ValidationException;

class ArchiveAccount
{
    public function archive(Account $account): Account
    {
        $account->update(['archived_at' => $account->archived_at ?? now()]);

        return $account;
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
                'account' => 'Akun ini sudah punya transaksi. Arsipkan saja supaya riwayatnya tetap utuh.',
            ]);
        }

        $account->delete();
    }
}
