<?php

namespace App\Actions\Transfers;

use App\Actions\Accounts\LockAccounts;
use App\Actions\Transactions\ValidatesTransactionTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kedua baris SELALU diubah dalam satu transaksi DB. Akun lama dan baru (bisa sampai 4)
 * dikunci sekaligus berurutan id, efek lama dibalik, efek baru diterapkan.
 */
class UpdateTransfer
{
    use ValidatesTransactionTarget;

    public function __construct(private readonly LockAccounts $lockAccounts) {}

    public function handle(User $user, TransferPair $pair, TransferInput $input): TransferPair
    {
        if ($input->fromAccountId === $input->toAccountId) {
            throw ValidationException::withMessages(['to_account_id' => 'Rekening asal dan tujuan harus berbeda.']);
        }

        return DB::transaction(function () use ($user, $pair, $input) {
            $accounts = $this->lockAccounts->handle($user, [
                $pair->out->account_id, $pair->in->account_id, $input->fromAccountId, $input->toAccountId,
            ]);
            $current = TransferPair::find($user, $pair->groupId(), lock: true);

            $from = $accounts->get($input->fromAccountId);
            $to = $accounts->get($input->toAccountId);
            assert($from !== null && $to !== null);

            if ($from->getKey() !== $current->out->account_id) {
                $this->assertAccountWritable($from, 'from_account_id');
            }
            if ($to->getKey() !== $current->in->account_id) {
                $this->assertAccountWritable($to, 'to_account_id');
            }

            // Balikkan efek lama.
            $accounts->get($current->out->account_id)?->decrement('balance', $current->out->amount);
            $accounts->get($current->in->account_id)?->decrement('balance', $current->in->amount);

            $common = ['occurred_on' => $input->occurredOn, 'note' => $input->note];
            $current->out->fill($common + ['account_id' => $from->getKey(), 'amount' => -abs($input->amount)])->save();
            $current->in->fill($common + ['account_id' => $to->getKey(), 'amount' => abs($input->amount)])->save();

            // Terapkan efek baru.
            $from->refresh()->increment('balance', $current->out->amount);
            $to->refresh()->increment('balance', $current->in->amount);

            return TransferPair::find($user, $current->groupId());
        });
    }
}
