<?php

namespace App\Reports;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletVisibility;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\CarbonImmutable;

/**
 * Terpakai budget = pengeluaran `posted` (tidak terhapus) di wallet SHARED household pada bulan itu.
 *
 * Keputusan #5/#8: budget milik bersama, jadi wallet privat siapa pun tidak pernah ikut dihitung.
 * Karena hanya wallet shared, angkanya sama untuk semua anggota dan tidak membuka data privat.
 * Dihitung langsung dari transaksi (index `idx_tx_category_date`), tidak disimpan.
 */
final class BudgetSpending
{
    /**
     * @return list<int>
     */
    public static function sharedWalletIds(Household $household): array
    {
        /** @var list<int> */
        return Wallet::query()
            ->where('household_id', $household->getKey())
            ->where('visibility', WalletVisibility::Shared->value)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Pengeluaran per kategori (positif, rupiah). Kategori tanpa pengeluaran tidak muncul.
     *
     * @param  list<int>|null  $categoryIds  null = semua kategori
     * @return array<int, int> [category_id => spent]
     */
    public static function byCategory(Household $household, CarbonImmutable $month, ?array $categoryIds = null): array
    {
        $wallets = self::sharedWalletIds($household);
        if ($wallets === [] || $categoryIds === []) {
            return [];
        }

        /** @var array<int, int|string> $rows */
        $rows = Transaction::query()
            ->where('household_id', $household->getKey())
            ->whereIn('wallet_id', $wallets)
            ->where('type', TransactionType::Expense->value)
            ->where('status', TransactionStatus::Posted->value)
            ->whereNotNull('category_id')
            ->when($categoryIds !== null, fn ($q) => $q->whereIn('category_id', $categoryIds))
            ->whereBetween('occurred_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->groupBy('category_id')
            ->selectRaw('category_id, -SUM(amount) AS spent')
            ->pluck('spent', 'category_id')
            ->all();

        return array_map(fn ($spent) => (int) $spent, $rows);
    }

    public static function forCategory(Household $household, CarbonImmutable $month, int $categoryId): int
    {
        return self::byCategory($household, $month, [$categoryId])[$categoryId] ?? 0;
    }
}
