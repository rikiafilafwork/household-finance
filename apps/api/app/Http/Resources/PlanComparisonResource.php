<?php

namespace App\Http\Resources;

use App\Queries\PlanComparison;
use App\Queries\PlanItemComparison;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Halaman Rencana: semua angka dari PlanComparisonQuery (satu sumber, Keputusan #11).
 *
 * @property PlanComparison $resource
 */
class PlanComparisonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        return [
            'month' => $plan->month->key(),
            'totals' => [
                'income' => $plan->totals['income'],
                'expense' => $plan->totals['expense'],
                'remaining_budget' => $plan->remainingBudget(),
                'still_to_spend' => $plan->stillToSpend(),
            ],
            'categories' => $plan->categories,
            'items' => $plan->items->map(fn (PlanItemComparison $row) => [
                ...(new PlanItemResource($row->item->setRelation('transaction', $row->transaction)))->toArray($request),
                'actual' => $row->actual,
                'percent' => $row->percent(),
            ])->all(),
        ];
    }
}
