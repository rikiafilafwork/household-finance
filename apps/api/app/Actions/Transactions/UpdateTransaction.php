<?php

namespace App\Actions\Transactions;

use App\Actions\Accounts\LockAccounts;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Update = balikkan efek lama, terapkan efek baru (Keputusan #3).
 * Pindah akun → dua akun dikunci berurutan id.
 */
class UpdateTransaction
{
    use ValidatesTransactionTarget;

    public function __construct(private readonly LockAccounts $lockAccounts) {}

    public function handle(User $user, Transaction $transaction, TransactionInput $input): Transaction
    {
        if (! $transaction->type->isCategorized() || $transaction->transfer_group_id !== null) {
            throw ValidationException::withMessages(['type' => 'Transfer dan penyesuaian saldo tidak diubah lewat endpoint ini.']);
        }

        return DB::transaction(function () use ($user, $transaction, $input) {
            $accounts = $this->lockAccounts->handle($user, [$transaction->account_id, $input->accountId]);

            /** @var Transaction $current */
            $current = Transaction::ownedBy($user)->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
            $oldAccount = $accounts->get($current->account_id);
            $newAccount = $accounts->get($input->accountId);
            assert($oldAccount !== null && $newAccount !== null);

            // Transaksi centang (Keputusan #8): nominal, tanggal, akun, catatan boleh diubah; jenis dan
            // kategori tidak, karena itulah yang membuatnya "milik" item checklist tersebut.
            if ($current->plan_item_id !== null && ($input->type !== $current->type || $input->categoryId !== $current->category_id)) {
                throw ValidationException::withMessages([
                    $input->type !== $current->type ? 'type' : 'category_id' => 'Transaksi ini terhubung ke item rencana. Batalkan centang dulu untuk mengubah jenis atau kategorinya.',
                ]);
            }

            if ($newAccount->isNot($oldAccount)) {
                $this->assertAccountWritable($newAccount);
            }
            $this->assertCategoryMatches($user, $input->categoryId, $input->type, $current->category_id);

            $oldAccount->decrement('balance', $current->amount);

            $current->fill([
                'account_id' => $newAccount->getKey(),
                'category_id' => $input->categoryId,
                'type' => $input->type,
                'amount' => $input->signedAmount(),
                'occurred_on' => $input->occurredOn,
                'note' => $input->note,
            ])->save();

            // Model akun di koleksi sama: refresh nilai supaya increment memakai angka terbaru.
            $newAccount->refresh()->increment('balance', $current->amount);

            return $current;
        });
    }
}
