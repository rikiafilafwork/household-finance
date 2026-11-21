<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keluarkan anggota (owner) atau keluar sendiri (anggota mana pun).
 *
 * Wallet privat miliknya tetap tersimpan (riwayat & saldo utuh) tapi tidak terlihat siapa pun,
 * termasuk owner household, sampai ia bergabung lagi (VisibleWallets mensyaratkan keanggotaan).
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
