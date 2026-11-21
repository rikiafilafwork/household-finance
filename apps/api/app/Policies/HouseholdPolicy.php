<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

/**
 * Keputusan #5. Matriks peran lengkap diuji di tests/Feature/Policies/RoleMatrixTest.php.
 */
class HouseholdPolicy
{
    public function view(User $user, Household $household): bool
    {
        return $household->hasMember($user);
    }

    /** Undang / cabut undangan / keluarkan anggota / ubah peran: owner saja. */
    public function manageMembers(User $user, Household $household): bool
    {
        return $household->isOwner($user);
    }
}
