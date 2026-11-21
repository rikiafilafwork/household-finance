<?php

namespace App\Http\Controllers\Api;

use App\Actions\Budgets\CopyBudgetsFromPreviousMonth;
use App\Actions\Budgets\SetMonthlyBudgets;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budgets\SetBudgetsRequest;
use App\Http\Resources\MonthlyBudgetResource;
use App\Jobs\CheckBudgetThresholds;
use App\Models\Budget;
use App\Models\Household;
use App\Support\BudgetMonth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Budget bulanan (Keputusan #8). `{month}` = `YYYY-MM` (dibatasi regex di route).
 */
class BudgetController extends Controller
{
    public function show(Household $household, string $month): MonthlyBudgetResource
    {
        Gate::authorize('view', [Budget::class, $household]);

        return new MonthlyBudgetResource($household, BudgetMonth::parse($month));
    }

    public function update(SetBudgetsRequest $request, Household $household, string $month, SetMonthlyBudgets $setBudgets): MonthlyBudgetResource
    {
        Gate::authorize('manage', [Budget::class, $household]);

        $start = BudgetMonth::parse($month);
        $setBudgets->handle($household, $start, $request->limits());

        // Limit yang diturunkan bisa langsung menembus ambang.
        foreach (array_keys($request->limits()) as $categoryId) {
            CheckBudgetThresholds::dispatch($household->id, $categoryId, $start->toDateString());
        }

        return new MonthlyBudgetResource($household, $start);
    }

    public function copyFromPrevious(Household $household, string $month, CopyBudgetsFromPreviousMonth $copy): JsonResponse
    {
        Gate::authorize('manage', [Budget::class, $household]);

        $start = BudgetMonth::parse($month);
        $copied = $copy->handle($household, $start);

        return (new MonthlyBudgetResource($household, $start))
            ->additional(['meta' => ['copied' => $copied]])
            ->response();
    }
}
