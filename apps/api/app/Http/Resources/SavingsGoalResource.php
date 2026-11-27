<?php

namespace App\Http\Resources;

use App\Models\SavingsGoal;
use App\Savings\MonthlyNeeded;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavingsGoal
 */
class SavingsGoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'name' => $this->name,
            'target_amount' => $this->target_amount,
            'allocated_amount' => $this->allocated_amount,
            'target_date' => $this->target_date?->toDateString(),
            'achieved_at' => $this->achieved_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'sort_order' => $this->sort_order,
            'percent' => Money::percentOf($this->allocated_amount, $this->target_amount),
            'reached' => $this->allocated_amount >= $this->target_amount,
            'monthly_needed' => $this->monthlyNeeded($request),
        ];
    }

    /** Sudah dihitung SavingsQuery; kalau belum (store/show/update), hitung dengan zona waktu user. */
    private function monthlyNeeded(Request $request): ?int
    {
        if (array_key_exists('monthly_needed', $this->resource->getAttributes())) {
            return $this->resource->getAttribute('monthly_needed');
        }

        $timezone = $request->user()->timezone;

        return MonthlyNeeded::for(
            $this->target_amount, $this->allocated_amount, $this->target_date?->toDateString(),
            CarbonImmutable::now($timezone)->toDateString(),
        );
    }
}
