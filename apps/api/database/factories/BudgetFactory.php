<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'category_id' => fn (array $attributes) => Category::factory()->create(['household_id' => $attributes['household_id']]),
            'month' => now()->startOfMonth()->toDateString(),
            'limit_amount' => 1_000_000,
        ];
    }

    public function forMonth(string $yearMonth): static
    {
        return $this->state(['month' => "{$yearMonth}-01"]);
    }
}
