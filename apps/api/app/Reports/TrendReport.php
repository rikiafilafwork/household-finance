<?php

namespace App\Reports;

use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\PlanComparisonQuery;

/**
 * Laporan → Tren: pemasukan vs pengeluaran (+ rencana) per bulan, N bulan berakhir di `end`.
 * Per bulan lewat PlanComparisonQuery, jadi 6 bulan terakhir = `trend` di Beranda.
 */
final class TrendReport
{
    public const MAX_MONTHS = 24;

    public function __construct(private readonly PlanComparisonQuery $plans) {}

    /**
     * @return list<array{month: string, income_planned: int, income_actual: int, expense_planned: int, expense_actual: int, net: int}>
     */
    public function for(User $user, PlanMonth $end, int $months): array
    {
        $start = $end;
        for ($i = 1; $i < $months; $i++) {
            $start = $start->previous();
        }

        return array_map(function (PlanMonth $month) use ($user) {
            $totals = $this->plans->for($user, $month)->totals;

            return [
                'month' => $month->key(),
                'income_planned' => $totals['income']['planned'],
                'income_actual' => $totals['income']['actual'],
                'expense_planned' => $totals['expense']['planned'],
                'expense_actual' => $totals['expense']['actual'],
                'net' => $totals['income']['actual'] - $totals['expense']['actual'],
            ];
        }, PlanMonth::range($start, $end));
    }
}
