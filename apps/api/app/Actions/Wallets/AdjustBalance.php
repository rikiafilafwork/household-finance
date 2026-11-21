<?php

namespace App\Actions\Wallets;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * User memasukkan saldo riil → transaksi `adjustment` sebesar selisihnya.
 * Tidak pernah mengubah `balance` secara langsung, jadi invariant ledger tetap utuh.
 *
 * Inputnya saldo ABSOLUT, sehingga idempoten secara alami: submit ganda menghasilkan
 * selisih 0 pada percobaan kedua dan tidak membuat transaksi apa pun.
 */
class AdjustBalance
{
    public function __construct(private readonly LockWallets $lockWallets) {}

    /**
     * @return array{0: Wallet, 1: Transaction|null}
     */
    public function handle(User $actor, Wallet $wallet, int $actualBalance, string $occurredOn, ?string $note = null): array
    {
        return DB::transaction(function () use ($actor, $wallet, $actualBalance, $occurredOn, $note) {
            $locked = $this->lockWallets->one($wallet->getKey());

            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['wallet' => 'Wallet ini sudah diarsip.']);
            }

            $delta = $actualBalance - $locked->balance;
            if ($delta === 0) {
                return [$locked, null];
            }

            $transaction = Transaction::create([
                'household_id' => $locked->household_id,
                'wallet_id' => $locked->getKey(),
                'category_id' => null,
                'type' => TransactionType::Adjustment,
                'status' => TransactionStatus::Posted,
                'amount' => $delta,
                'occurred_on' => $occurredOn,
                'note' => $note ?? 'Penyesuaian saldo',
                'created_by' => $actor->getKey(),
            ]);

            $locked->increment('balance', $delta);

            return [$locked, $transaction];
        });
    }
}
