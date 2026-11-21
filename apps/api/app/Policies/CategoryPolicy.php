<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Household;
use App\Models\User;

/**
 * Keputusan #5: kelola kategori → owner; member hanya lihat.
 */
class CategoryPolicy
{
    public function viewAny(User $user, Household $household): bool
    {
        return $household->hasMember($user);
    }

    public function create(User $user, Household $household): bool
    {
        return $household->isOwner($user);
    }

    /** Ubah, arsip, hapus. */
    public function manage(User $user, Category $category): bool
    {
        return $category->household->isOwner($user);
    }
}
