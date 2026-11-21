<?php

namespace App\Reports;

use App\Enums\WalletVisibility;
use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;

/**
 * SATU sumber kebenaran visibilitas wallet (Keputusan #5, ADR-005).
 *
 * - shared  → terlihat semua anggota household
 * - private → hanya pemiliknya, TERMASUK tidak terlihat oleh owner household
 *
 * Semua query wallet dan transaksi (list, detail, pencarian, laporan, export) wajib lewat sini.
 */
final class VisibleWallets
{
    /**
     * @return Builder<Wallet>
     */
    public static function query(User $user, Household $household): Builder
    {
        return Wallet::query()
            ->where('household_id', $household->getKey())
            ->where(function (Builder $query) use ($user) {
                $query->where('visibility', WalletVisibility::Shared->value)
                    ->orWhere(function (Builder $query) use ($user) {
                        $query->where('visibility', WalletVisibility::Private->value)
                            ->where('owner_user_id', $user->getKey());
                    });
            });
    }

    /**
     * Daftar id wallet terlihat (jumlahnya kecil) → dipakai sebagai `wallet_id IN (...)`.
     *
     * @return list<int>
     */
    public static function for(User $user, Household $household): array
    {
        /** @var list<int> */
        return self::query($user, $household)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function canSee(User $user, Wallet $wallet): bool
    {
        if (! $wallet->household->hasMember($user)) {
            return false;
        }

        return ! $wallet->isPrivate() || $wallet->owner_user_id === $user->getKey();
    }
}
