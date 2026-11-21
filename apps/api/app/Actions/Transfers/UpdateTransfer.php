<?php

namespace App\Actions\Transfers;

use App\Actions\Transactions\ValidatesTransactionTarget;
use App\Actions\Wallets\LockWallets;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kedua baris SELALU diubah dalam satu transaksi DB. Wallet lama dan baru (bisa sampai 4)
 * dikunci sekaligus berurutan id, efek lama dibalik, efek baru diterapkan.
 */
class UpdateTransfer
{
    use ValidatesTransactionTarget;

    public function __construct(private readonly LockWallets $lockWallets) {}

    public function handle(Household $household, User $actor, TransferPair $pair, TransferInput $input): TransferPair
    {
        if ($input->fromWalletId === $input->toWalletId) {
            throw ValidationException::withMessages(['to_wallet_id' => 'Wallet asal dan tujuan harus berbeda.']);
        }

        return DB::transaction(function () use ($household, $actor, $pair, $input) {
            $wallets = $this->lockWallets->handle([
                $pair->out->wallet_id, $pair->in->wallet_id, $input->fromWalletId, $input->toWalletId,
            ]);
            $current = TransferPair::find($household, $pair->groupId(), lock: true);

            $from = $wallets->get($input->fromWalletId);
            $to = $wallets->get($input->toWalletId);
            assert($from !== null && $to !== null);

            if ($from->getKey() !== $current->out->wallet_id) {
                $this->assertWalletWritable($from, $household, $actor, 'from_wallet_id');
            }
            if ($to->getKey() !== $current->in->wallet_id) {
                $this->assertWalletWritable($to, $household, $actor, 'to_wallet_id');
            }

            // Balikkan efek lama.
            $wallets->get($current->out->wallet_id)?->decrement('balance', $current->out->amount);
            $wallets->get($current->in->wallet_id)?->decrement('balance', $current->in->amount);

            $common = ['occurred_on' => $input->occurredOn, 'note' => $input->note, 'updated_by' => $actor->getKey()];
            $current->out->fill($common + ['wallet_id' => $from->getKey(), 'amount' => -abs($input->amount)])->save();
            $current->in->fill($common + ['wallet_id' => $to->getKey(), 'amount' => abs($input->amount)])->save();

            // Terapkan efek baru.
            $from->refresh()->increment('balance', $current->out->amount);
            $to->refresh()->increment('balance', $current->in->amount);

            return TransferPair::find($household, $current->groupId());
        });
    }
}
