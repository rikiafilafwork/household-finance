<?php

namespace App\Actions\Transfers;

use App\Actions\Wallets\LockWallets;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteTransfer
{
    public function __construct(private readonly LockWallets $lockWallets) {}

    /** Kedua baris di-soft delete dan kedua efek saldo dibalik dalam satu transaksi DB. */
    public function handle(Household $household, User $actor, TransferPair $pair): void
    {
        DB::transaction(function () use ($household, $actor, $pair) {
            $wallets = $this->lockWallets->handle([$pair->out->wallet_id, $pair->in->wallet_id]);
            $current = TransferPair::find($household, $pair->groupId(), lock: true);

            foreach ([$current->out, $current->in] as $leg) {
                $wallets->get($leg->wallet_id)?->decrement('balance', $leg->amount);
                $leg->forceFill(['updated_by' => $actor->getKey()])->save();
                $leg->delete();
            }
        });
    }
}
