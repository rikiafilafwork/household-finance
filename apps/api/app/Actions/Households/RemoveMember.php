<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keluarkan anggota (owner) atau keluar sendiri (anggota mana pun).
 * Wallet private milik user tersebut ditangani saat modul wallet ada (#15).
 */
class RemoveMember
{
    public function __construct(private readonly EnsureOwnerRemains $ensureOwnerRemains) {}

    public function handle(Household $household, User $member): void
    {
        DB::transaction(function () use ($household, $member) {
            $this->ensureOwnerRemains->lockAndCheck($household, $member, stillOwner: false);

            $household->members()->detach($member->getKey());
        });
    }
}
