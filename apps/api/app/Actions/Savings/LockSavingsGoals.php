<?php

namespace App\Actions\Savings;

use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Kunci tujuan tabungan milik user (SELECT ... FOR UPDATE) berurutan berdasarkan id, supaya dua
 * pindah alokasi berlawanan arah tidak deadlock. Wajib dipanggil SETELAH LockAccounts
 * (urutan kunci: rekening → tujuan), di dalam DB::transaction. Milik user lain = 404.
 */
class LockSavingsGoals
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, SavingsGoal> dikunci, di-key berdasarkan id
     */
    public function handle(User $user, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        $goals = SavingsGoal::ownedBy($user)->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        abort_if($goals->count() !== count($ids), 404, 'Tujuan tabungan tidak ditemukan.');

        return $goals;
    }
}
