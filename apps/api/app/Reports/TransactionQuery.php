<?php

namespace App\Reports;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query transaksi yang SELALU dibatasi milik satu user (Keputusan #5), eksplisit, tidak
 * bergantung pada sesi login. Dipakai daftar transaksi sekarang, laporan & export nanti.
 */
final class TransactionQuery
{
    /**
     * @param  array{account_id?: int|string, category_id?: int|string, type?: string, from?: string, to?: string, q?: string}  $filters
     * @return Builder<Transaction>
     */
    public static function for(User $user, array $filters = []): Builder
    {
        return Transaction::ownedBy($user)
            ->when(isset($filters['account_id']), fn (Builder $q) => $q->where('account_id', (int) $filters['account_id']))
            ->when(isset($filters['category_id']), fn (Builder $q) => $q->where('category_id', (int) $filters['category_id']))
            ->when(isset($filters['type']), fn (Builder $q) => $q->where('type', $filters['type']))
            ->when(isset($filters['from']), fn (Builder $q) => $q->where('occurred_on', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->where('occurred_on', '<=', $filters['to']))
            ->when(isset($filters['q']) && $filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = addcslashes((string) $filters['q'], '\\%_');
                $q->where('note', 'like', "%{$term}%");
            });
    }
}
