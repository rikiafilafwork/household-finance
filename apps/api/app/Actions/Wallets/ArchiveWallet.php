<?php

namespace App\Actions\Wallets;

use App\Models\Wallet;
use Illuminate\Validation\ValidationException;

class ArchiveWallet
{
    public function archive(Wallet $wallet): Wallet
    {
        $wallet->update(['archived_at' => $wallet->archived_at ?? now()]);

        return $wallet;
    }

    public function unarchive(Wallet $wallet): Wallet
    {
        $wallet->update(['archived_at' => null]);

        return $wallet;
    }

    /**
     * Hapus permanen hanya kalau belum pernah ada transaksi (termasuk yang soft-deleted,
     * karena FK & riwayat audit). Selain itu wajib arsip.
     */
    public function delete(Wallet $wallet): void
    {
        if ($wallet->transactions()->withTrashed()->exists()) {
            throw ValidationException::withMessages([
                'wallet' => 'Wallet ini sudah punya transaksi. Arsipkan saja supaya riwayatnya tetap utuh.',
            ]);
        }

        $wallet->delete();
    }
}
