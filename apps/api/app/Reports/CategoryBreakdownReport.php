<?php

namespace App\Reports;

use App\Enums\CategoryKind;
use App\Models\Category;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\PlanComparisonQuery;

/**
 * Laporan → Kategori: rencana vs aktual per kategori untuk satu bulan atau rentang bulan, satu
 * jenis (pemasukan/pengeluaran). Per bulan lewat PlanComparisonQuery lalu dijumlahkan, jadi
 * rentang satu bulan = baris `categories` Beranda/Rencana bulan itu.
 *
 * Rentang berbasis BULAN (bukan tanggal bebas) karena rencana itu bulanan: rencana setengah bulan
 * tidak bermakna. `percent` = bagian kategori dari total aktual (dibulatkan ke bawah, tanpa float).
 */
final class CategoryBreakdownReport
{
    public const MAX_MONTHS = 24;

    public function __construct(private readonly PlanComparisonQuery $plans) {}

    /**
     * @return array{from: string, to: string, kind: string, total_planned: int, total_actual: int, categories: list<array<string, mixed>>}
     */
    public function for(User $user, PlanMonth $from, PlanMonth $to, CategoryKind $kind): array
    {
        $acc = [];
        foreach (PlanMonth::range($from, $to) as $month) {
            foreach ($this->plans->for($user, $month)->categories as $row) {
                if ($row['type'] !== $kind->value) {
                    continue;
                }
                $key = $row['category_id'] ?? 'none';
                $acc[$key] ??= ['category_id' => $row['category_id'], 'name' => $row['name'], 'planned' => 0, 'actual' => 0];
                $acc[$key]['planned'] += $row['planned'];
                $acc[$key]['actual'] += $row['actual'];
            }
        }

        $colors = Category::ownedBy($user)->whereKey(array_filter(array_column($acc, 'category_id')))->pluck('color', 'id');
        $totalActual = array_sum(array_column($acc, 'actual'));

        $rows = array_map(fn (array $r) => $r + [
            'color' => $r['category_id'] === null ? null : $colors->get($r['category_id']),
            'percent' => $totalActual > 0 ? intdiv($r['actual'] * 100, $totalActual) : 0,
        ], array_values($acc));
        usort($rows, fn (array $a, array $b) => [-$a['actual'], -$a['planned'], $a['name']] <=> [-$b['actual'], -$b['planned'], $b['name']]);

        return [
            'from' => $from->key(),
            'to' => $to->key(),
            'kind' => $kind->value,
            'total_planned' => array_sum(array_column($rows, 'planned')),
            'total_actual' => $totalActual,
            'categories' => $rows,
        ];
    }
}
