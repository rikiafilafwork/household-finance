<?php

namespace App\Actions\Transactions;

use App\Actions\Wallets\LockWallets;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete = soft delete + balikkan efek saldo (Keputusan #3).
 */
class DeleteTransaction
{
    public function __construct(private readonly LockWallets $lockWallets) {}

    public function handle(User $actor, Transaction $transaction): void
    {
        if ($transaction->transfer_group_id !== null) {
            throw ValidationException::withMessages(['type' => 'Hapus transfer lewat endpoint transfer supaya kedua sisinya ikut terhapus.']);
        }

        DB::transaction(function () use ($actor, $transaction) {
            $wallet = $this->lockWallets->one($transaction->wallet_id);

            /** @var Transaction|null $current */
            $current = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first();
            if ($current === null) {
                return; // sudah terhapus oleh request lain: idempoten
            }

            if ($current->status === TransactionStatus::Posted) {
                $wallet->decrement('balance', $current->amount);
            }

            $current->forceFill(['updated_by' => $actor->getKey()])->save();
            $current->delete();
        });
    }
}
