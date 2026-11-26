<?php

namespace App\Reports;

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\PlanComparison;
use App\Queries\PlanComparisonQuery;

/**
 * Laporan → Ringkasan satu bulan (Keputusan #14). Angka rencana & aktual dari PlanComparisonQuery,
 * jadi SAMA dengan halaman Rencana dan Beranda untuk bulan yang sama (lihat DashboardConsistencyTest).
 *
 *  - net = aktual pemasukan − aktual pengeluaran.
 *  - previous = angka yang sama untuk bulan sebelumnya + selisihnya.
 *  - unrecorded = total penyesuaian saldo bulan itu (Keputusan #6: selisih dompet "tak tercatat"),
 *    negatif = uang hilang yang tidak pernah dicatat. Tidak termasuk pemasukan/pengeluaran.
 */
final class MonthlySummaryReport
{
    public function __construct(private readonly PlanComparisonQuery $plans) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, PlanMonth $month): array
    {
        $current = $this->plans->for($user, $month);
        $previous = $this->plans->for($user, $month->previous());

        $now = self::figures($current);
        $before = self::figures($previous);

        return [
            'month' => $month->key(),
            'income' => $current->totals['income'],
            'expense' => $current->totals['expense'],
            'net' => $now['net'],
            'remaining_budget' => $current->remainingBudget(),
            'unrecorded' => $this->adjustments($user, $month),
            'previous' => [
                'month' => $month->previous()->key(),
                'income' => $before['income'],
                'expense' => $before['expense'],
                'net' => $before['net'],
                'change' => [
                    'income' => $now['income'] - $before['income'],
                    'expense' => $now['expense'] - $before['expense'],
                    'net' => $now['net'] - $before['net'],
                ],
            ],
        ];
    }

    /**
     * @return array{income: int, expense: int, net: int}
     */
    private static function figures(PlanComparison $plan): array
    {
        $income = $plan->totals['income']['actual'];
        $expense = $plan->totals['expense']['actual'];

        return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
    }

    private function adjustments(User $user, PlanMonth $month): int
    {
        return (int) Transaction::ownedBy($user)
            ->where('type', TransactionType::Adjustment->value)
            ->whereBetween('occurred_on', [$month->firstDay(), $month->lastDay()])
            ->sum('amount');
    }
}
