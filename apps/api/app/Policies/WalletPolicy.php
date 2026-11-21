<?php

namespace App\Policies;

use App\Enums\WalletVisibility;
use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;
use App\Reports\VisibleWallets;

/**
 * Keputusan #5: buat/arsip wallet shared → owner; wallet private → pemiliknya sendiri.
 */
class WalletPolicy
{
    public function view(User $user, Wallet $wallet): bool
    {
        return VisibleWallets::canSee($user, $wallet);
    }

    public function create(User $user, Household $household, WalletVisibility $visibility): bool
    {
        if (! $household->hasMember($user)) {
            return false;
        }

        return $visibility === WalletVisibility::Private || $household->isOwner($user);
    }

    /** Ubah, arsip, hapus. */
    public function manage(User $user, Wallet $wallet): bool
    {
        if ($wallet->isPrivate()) {
            return $wallet->owner_user_id === $user->getKey() && $wallet->household->hasMember($user);
        }

        return $wallet->household->isOwner($user);
    }
}
