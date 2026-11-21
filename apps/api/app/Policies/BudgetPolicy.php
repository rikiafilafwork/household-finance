<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

/**
 * Keputusan #5: kelola budget → owner; member hanya lihat.
 */
class BudgetPolicy
{
    public function view(User $user, Household $household): bool
    {
        return $household->hasMember($user);
    }

    public function manage(User $user, Household $household): bool
    {
        return $household->isOwner($user);
    }
}
