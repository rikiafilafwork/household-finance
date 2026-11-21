<?php

namespace App\Actions\Wallets;

use App\Models\Wallet;
use Illuminate\Support\Collection;

/**
 * Kunci satu atau beberapa wallet (SELECT ... FOR UPDATE) SELALU berurutan berdasarkan id.
 * Dua operasi yang menyentuh wallet A & B dari arah berlawanan jadi tidak deadlock.
 * Wajib dipanggil di dalam DB::transaction.
 */
class LockWallets
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, Wallet> dikunci, di-key berdasarkan id
     */
    public function handle(array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        $wallets = Wallet::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        abort_if($wallets->count() !== count($ids), 404, 'Wallet tidak ditemukan.');

        return $wallets;
    }

    public function one(int $id): Wallet
    {
        /** @var Wallet */
        return $this->handle([$id])->first();
    }
}
