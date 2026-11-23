<?php

namespace App\Actions\Plan;

use App\Models\PlanItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lewati item bulan ini (mis. tagihan yang tidak ada). Item yang di-skip keluar dari rencana.
 * Item yang sudah dicentang tidak bisa di-skip (409): itu sudah terjadi.
 */
class SkipPlanItem
{
    public function skip(User $user, PlanItem $item): PlanItem
    {
        return DB::transaction(function () use ($user, $item) {
            $locked = $this->lock($user, $item);

            abort_if($locked->isDone(), 409, 'Item ini sudah dicentang, jadi tidak bisa dilewati.');

            if ($locked->skipped_at === null) {
                $locked->forceFill(['skipped_at' => now()])->save();
            }

            return $locked;
        });
    }

    public function unskip(User $user, PlanItem $item): PlanItem
    {
        return DB::transaction(function () use ($user, $item) {
            $locked = $this->lock($user, $item);
            $locked->forceFill(['skipped_at' => null])->save();

            return $locked;
        });
    }

    private function lock(User $user, PlanItem $item): PlanItem
    {
        /** @var PlanItem */
        return PlanItem::ownedBy($user)->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
    }
}
