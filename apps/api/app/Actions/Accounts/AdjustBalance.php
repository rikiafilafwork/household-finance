<?php

namespace App\Actions\Accounts;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
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
    public function __construct(private readonly LockAccounts $lockAccounts) {}

    /**
     * @return array{0: Account, 1: Transaction|null}
     */
    public function handle(User $user, Account $account, int $actualBalance, string $occurredOn, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $account, $actualBalance, $occurredOn, $note) {
            $locked = $this->lockAccounts->one($user, $account->getKey());

            if ($locked->isArchived()) {
                throw ValidationException::withMessages(['account' => 'Rekening ini sudah diarsip.']);
            }

            $delta = $actualBalance - $locked->balance;
            if ($delta === 0) {
                return [$locked, null];
            }

            $transaction = Transaction::create([
                'user_id' => $user->getKey(),
                'account_id' => $locked->getKey(),
                'category_id' => null,
                'type' => TransactionType::Adjustment,
                'amount' => $delta,
                'occurred_on' => $occurredOn,
                'note' => $note ?? 'Penyesuaian saldo',
            ]);

            $locked->increment('balance', $delta);

            return [$locked, $transaction];
        });
    }
}
