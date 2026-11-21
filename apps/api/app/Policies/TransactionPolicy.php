<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use App\Reports\VisibleWallets;

/**
 * Keputusan #5: lihat = wallet-nya terlihat. Edit/hapus transaksi orang lain di wallet shared
 * hanya owner household; member hanya transaksinya sendiri.
 */
class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return VisibleWallets::canSee($user, $transaction->wallet);
    }

    public function update(User $user, Transaction $transaction): bool
    {
        if (! $this->view($user, $transaction)) {
            return false;
        }

        return $transaction->created_by === $user->getKey() || $transaction->household->isOwner($user);
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $this->update($user, $transaction);
    }
}
