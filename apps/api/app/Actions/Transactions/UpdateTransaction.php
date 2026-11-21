<?php

namespace App\Actions\Transactions;

use App\Actions\Wallets\LockWallets;
use App\Enums\TransactionStatus;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Update = balikkan efek lama, terapkan efek baru (Keputusan #3).
 * Pindah wallet → dua wallet dikunci berurutan id.
 */
class UpdateTransaction
{
    use ValidatesTransactionTarget;

    public function __construct(private readonly LockWallets $lockWallets) {}

    public function handle(Household $household, User $actor, Transaction $transaction, TransactionInput $input): Transaction
    {
        if (! $transaction->type->isCategorized() || $transaction->transfer_group_id !== null) {
            throw ValidationException::withMessages(['type' => 'Transfer dan penyesuaian saldo tidak diubah lewat endpoint ini.']);
        }

        return DB::transaction(function () use ($household, $actor, $transaction, $input) {
            $wallets = $this->lockWallets->handle([$transaction->wallet_id, $input->walletId]);

            /** @var Transaction $current */
            $current = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
            $oldWallet = $wallets->get($current->wallet_id);
            $newWallet = $wallets->get($input->walletId);
            assert($oldWallet !== null && $newWallet !== null);

            if ($newWallet->isNot($oldWallet)) {
                $this->assertWalletWritable($newWallet, $household, $actor);
            }
            $this->assertCategoryMatches($input->categoryId, $input->type, $household);

            if ($current->status === TransactionStatus::Posted) {
                $oldWallet->decrement('balance', $current->amount);
            }

            $current->fill([
                'wallet_id' => $newWallet->getKey(),
                'category_id' => $input->categoryId,
                'type' => $input->type,
                'amount' => $input->signedAmount(),
                'occurred_on' => $input->occurredOn,
                'note' => $input->note,
                'updated_by' => $actor->getKey(),
            ])->save();

            if ($current->status === TransactionStatus::Posted) {
                // Model wallet di koleksi sama: refresh nilai supaya increment memakai angka terbaru.
                $newWallet->refresh()->increment('balance', $current->amount);
            }

            return $current;
        });
    }
}
