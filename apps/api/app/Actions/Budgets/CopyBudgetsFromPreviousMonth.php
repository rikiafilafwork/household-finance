<?php

namespace App\Actions\Budgets;

use App\Models\Budget;
use App\Models\Household;
use Carbon\CarbonImmutable;

/**
 * "Salin dari bulan lalu": limit bulan sebelumnya disalin untuk kategori yang BELUM punya
 * budget di bulan ini (yang sudah diatur tidak ditimpa). Kategori yang diarsip dilewati.
 * Aman diulang: menjalankan dua kali tidak membuat dobel (unique + insertOrIgnore).
 */
class CopyBudgetsFromPreviousMonth
{
    /** @return int jumlah budget yang disalin */
    public function handle(Household $household, CarbonImmutable $month): int
    {
        $now = now();

        $rows = Budget::query()
            ->join('categories', 'categories.id', '=', 'budgets.category_id')
            ->where('budgets.household_id', $household->getKey())
            ->where('budgets.month', $month->subMonthNoOverflow()->toDateString())
            ->whereNull('categories.archived_at')
            ->get(['budgets.category_id', 'budgets.limit_amount'])
            ->map(fn (Budget $b) => [
                'household_id' => $household->getKey(),
                'category_id' => $b->category_id,
                'month' => $month->toDateString(),
                'limit_amount' => $b->limit_amount,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        return $rows === [] ? 0 : Budget::insertOrIgnore($rows);
    }
}
