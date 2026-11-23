<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Kunci satu atau beberapa akun milik user (SELECT ... FOR UPDATE) SELALU berurutan berdasarkan id.
 * Dua operasi yang menyentuh akun A & B dari arah berlawanan jadi tidak deadlock.
 * Akun milik user lain diperlakukan sebagai tidak ada (404). Wajib dipanggil di dalam DB::transaction.
 */
class LockAccounts
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, Account> dikunci, di-key berdasarkan id
     */
    public function handle(User $user, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        $accounts = Account::ownedBy($user)->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        abort_if($accounts->count() !== count($ids), 404, 'Akun tidak ditemukan.');

        return $accounts;
    }

    public function one(User $user, int $id): Account
    {
        /** @var Account */
        return $this->handle($user, [$id])->first();
    }
}
