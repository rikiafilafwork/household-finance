<?php

namespace App\Actions\Plan;

use App\Actions\Accounts\LockAccounts;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Batal centang (Keputusan #8), satu transaksi DB: soft delete transaksi, plan_item_id = NULL
 * (UNIQUE (plan_item_id) ikut menghitung baris soft-deleted; tanpa NULL item tidak bisa dicentang
 * ulang), dan saldo dikoreksi. Urutan kunci sama dengan CheckPlanItem: item → akun.
 * Idempoten: item yang belum dicentang dibiarkan.
 */
class UncheckPlanItem
{
    public function __construct(private readonly LockAccounts $lockAccounts) {}

    public function handle(User $user, PlanItem $item): PlanItem
    {
        return DB::transaction(function () use ($user, $item) {
            /** @var PlanItem $locked */
            $locked = PlanItem::ownedBy($user)->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            $linked = Transaction::ownedBy($user)->where('plan_item_id', $locked->getKey())->first();
            if ($linked === null) {
                return $locked;
            }

            $account = $this->lockAccounts->one($user, $linked->account_id);
            /** @var Transaction $current */
            $current = Transaction::ownedBy($user)->whereKey($linked->getKey())->lockForUpdate()->firstOrFail();

            $account->decrement('balance', $current->amount);
            $current->forceFill(['plan_item_id' => null])->save();
            $current->delete();

            return $locked;
        });
    }
}
