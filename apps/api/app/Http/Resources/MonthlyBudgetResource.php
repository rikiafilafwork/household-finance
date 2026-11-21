<?php

namespace App\Http\Resources;

use App\Models\Budget;
use App\Models\Household;
use App\Reports\BudgetSpending;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Budget satu bulan: limit, terpakai, sisa, persen per kategori + total.
 * Persen dibulatkan ke bawah (79,9% → 79) supaya cocok dengan ambang alert (Keputusan #1).
 */
class MonthlyBudgetResource extends JsonResource
{
    public function __construct(private readonly Household $household, private readonly CarbonImmutable $month)
    {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $budgets = Budget::query()
            ->with('category')
            ->where('household_id', $this->household->getKey())
            ->where('month', $this->month->toDateString())
            ->get()
            ->sortBy(fn (Budget $b) => $b->category->name)
            ->values();

        $spent = BudgetSpending::byCategory($this->household, $this->month);

        $items = $budgets->map(fn (Budget $b) => [
            'id' => $b->id,
            'category' => new CategoryResource($b->category),
            ...self::numbers($b->limit_amount, $spent[$b->category_id] ?? 0),
        ])->all();

        $limit = (int) $budgets->sum('limit_amount');
        $budgetedSpent = (int) $budgets->sum(fn (Budget $b) => $spent[$b->category_id] ?? 0);

        return [
            'month' => $this->month->format('Y-m'),
            'items' => $items,
            'totals' => [
                ...self::numbers($limit, $budgetedSpent),
                // Pengeluaran di kategori yang tidak diberi budget (tetap berguna di dashboard).
                'unbudgeted_spent' => array_sum($spent) - $budgetedSpent,
            ],
        ];
    }

    /**
     * @return array{limit_amount: int, spent: int, remaining: int, percent: int|null}
     */
    private static function numbers(int $limit, int $spent): array
    {
        return [
            'limit_amount' => $limit,
            'spent' => $spent,
            'remaining' => $limit - $spent,
            'percent' => $limit > 0 ? Money::percentOf($spent, $limit) : null,
        ];
    }
}
