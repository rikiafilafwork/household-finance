<?php

namespace App\Actions\Transfers;

use App\Actions\Accounts\LockAccounts;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteTransfer
{
    public function __construct(private readonly LockAccounts $lockAccounts) {}

    /** Kedua baris di-soft delete dan kedua efek saldo dibalik dalam satu transaksi DB. */
    public function handle(User $user, TransferPair $pair): void
    {
        DB::transaction(function () use ($user, $pair) {
            $accounts = $this->lockAccounts->handle($user, [$pair->out->account_id, $pair->in->account_id]);
            $current = TransferPair::find($user, $pair->groupId(), lock: true);

            foreach ([$current->out, $current->in] as $leg) {
                $accounts->get($leg->account_id)?->decrement('balance', $leg->amount);
                $leg->delete();
            }
        });
    }
}
