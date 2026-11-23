<?php

namespace App\Actions\Plan;

use App\Models\PlanItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Item yang sudah dicentang tidak bisa dihapus (409): batalkan centang dulu (Keputusan #8).
 * Item dikunci dulu, jadi tidak bisa balapan dengan CheckPlanItem (yang juga mengunci item).
 */
class DeletePlanItem
{
    public function handle(User $user, PlanItem $item): void
    {
        DB::transaction(function () use ($user, $item) {
            /** @var PlanItem $locked */
            $locked = PlanItem::ownedBy($user)->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            abort_if($locked->isDone(), 409, 'Item ini sudah dicentang. Batalkan centang dulu sebelum menghapus.');

            $locked->delete();
        });
    }
}
