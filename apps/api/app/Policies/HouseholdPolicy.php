<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

/**
 * Versi awal: cukup keanggotaan. Matriks peran lengkap (Keputusan #5) dilengkapi di #13 dan #26.
 */
class HouseholdPolicy
{
    public function view(User $user, Household $household): bool
    {
        return $household->hasMember($user);
    }
}
