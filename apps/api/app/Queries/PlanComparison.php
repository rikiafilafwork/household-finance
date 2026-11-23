<?php

namespace App\Queries;

use App\Plan\PlanMonth;
use Illuminate\Support\Collection;

/**
 * @phpstan-type Totals array{planned: int, actual: int, unplanned: int, unpaid: int, envelope_remaining: int}
 */
final readonly class PlanComparison
{
    /**
     * @param  Collection<int, PlanItemComparison>  $items
     * @param  array{income: Totals, expense: Totals}  $totals
     * @param  list<array{type: string, category_id: int|null, name: string, planned: int, actual: int}>  $categories
     */
    public function __construct(
        public PlanMonth $month,
        public Collection $items,
        public array $totals,
        public array $categories,
    ) {}

    /** Rencana pengeluaran − aktual pengeluaran; negatif = lewat anggaran. */
    public function remainingBudget(): int
    {
        return $this->totals['expense']['planned'] - $this->totals['expense']['actual'];
    }

    /**
     * Pengeluaran yang masih akan keluar bulan ini menurut rencana: checklist belum dibayar +
     * sisa ruang amplop. Saldo akun − angka ini = "uang bebas" (dihitung web untuk bulan berjalan).
     */
    public function stillToSpend(): int
    {
        return $this->totals['expense']['unpaid'] + $this->totals['expense']['envelope_remaining'];
    }

    public function item(int $id): ?PlanItemComparison
    {
        return $this->items->first(fn (PlanItemComparison $r) => $r->item->getKey() === $id);
    }
}
