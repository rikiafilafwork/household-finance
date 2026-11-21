<?php

namespace App\Reports;

use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query transaksi household yang SELALU dibatasi wallet yang terlihat user (VisibleWallets).
 * Dipakai daftar transaksi sekarang, laporan & export nanti.
 */
final class TransactionQuery
{
    /**
     * @param  array{wallet_id?: int|string, category_id?: int|string, type?: string, status?: string, from?: string, to?: string, q?: string}  $filters
     * @return Builder<Transaction>
     */
    public static function for(User $user, Household $household, array $filters = []): Builder
    {
        $visible = VisibleWallets::for($user, $household);

        return Transaction::query()
            ->where('household_id', $household->getKey())
            ->whereIn('wallet_id', $visible === [] ? [0] : $visible)
            ->when(isset($filters['wallet_id']), fn (Builder $q) => $q->where('wallet_id', (int) $filters['wallet_id']))
            ->when(isset($filters['category_id']), fn (Builder $q) => $q->where('category_id', (int) $filters['category_id']))
            ->when(isset($filters['type']), fn (Builder $q) => $q->where('type', $filters['type']))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['from']), fn (Builder $q) => $q->where('occurred_on', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->where('occurred_on', '<=', $filters['to']))
            ->when(isset($filters['q']) && $filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = addcslashes((string) $filters['q'], '\\%_');
                $q->where('note', 'like', "%{$term}%");
            });
    }
}
