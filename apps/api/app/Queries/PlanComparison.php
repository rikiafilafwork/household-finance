<?php

namespace App\Queries;

use App\Plan\PlanMonth;
use Illuminate\Support\Collection;

final readonly class PlanComparison
{
    /**
     * @param  Collection<int, PlanItemComparison>  $items
     * @param  array{income: array{planned: int, actual: int, unplanned: int, unpaid: int}, expense: array{planned: int, actual: int, unplanned: int, unpaid: int}}  $totals
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

    public function item(int $id): ?PlanItemComparison
    {
        return $this->items->first(fn (PlanItemComparison $r) => $r->item->getKey() === $id);
    }
}
