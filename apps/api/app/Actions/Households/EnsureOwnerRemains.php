<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Household harus selalu punya minimal satu owner.
 *
 * Baris household dikunci dulu (lockForUpdate) supaya dua owner yang saling menurunkan
 * peran secara bersamaan tersusun serial dan tidak berakhir dengan nol owner.
 * Harus dipanggil di dalam DB::transaction.
 */
class EnsureOwnerRemains
{
    public function lockAndCheck(Household $household, User $member, bool $stillOwner): void
    {
        Household::query()->whereKey($household->getKey())->lockForUpdate()->firstOrFail();

        if ($stillOwner || $household->roleOf($member) !== HouseholdRole::Owner) {
            return;
        }

        $otherOwners = $household->members()
            ->wherePivot('role', HouseholdRole::Owner->value)
            ->whereKeyNot($member->getKey())
            ->count();

        if ($otherOwners === 0) {
            throw ValidationException::withMessages([
                'role' => 'Household harus punya minimal satu owner. Jadikan anggota lain owner terlebih dulu.',
            ]);
        }
    }
}
