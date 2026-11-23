<?php

namespace App\Actions\Transactions;

use App\Actions\Accounts\LockAccounts;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete = soft delete + balikkan efek saldo (Keputusan #3). plan_item_id dilepas (NULL) supaya
 * item checklist bisa dicentang ulang: UNIQUE (plan_item_id) ikut menghitung baris soft-deleted (#8).
 */
class DeleteTransaction
{
    public function __construct(private readonly LockAccounts $lockAccounts) {}

    public function handle(User $user, Transaction $transaction): void
    {
        if ($transaction->transfer_group_id !== null) {
            throw ValidationException::withMessages(['type' => 'Hapus transfer lewat endpoint transfer supaya kedua sisinya ikut terhapus.']);
        }

        DB::transaction(function () use ($user, $transaction) {
            $account = $this->lockAccounts->one($user, $transaction->account_id);

            /** @var Transaction|null $current */
            $current = Transaction::ownedBy($user)->whereKey($transaction->getKey())->lockForUpdate()->first();
            if ($current === null) {
                return; // sudah terhapus oleh request lain: idempoten
            }

            $account->decrement('balance', $current->amount);

            $current->forceFill(['plan_item_id' => null])->save();
            $current->delete();
        });
    }
}
