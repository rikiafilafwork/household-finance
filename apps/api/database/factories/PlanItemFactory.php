<?php

namespace Database\Factories;

use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanItem>
 */
class PlanItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'month' => '2026-11-01',
            'type' => PlanItemType::Expense,
            'mode' => PlanMode::Checklist,
            'name' => fake()->randomElement(['Listrik', 'Internet', 'Cicilan', 'Pulsa']),
            'category_id' => fn (array $a) => Category::factory()->create(['user_id' => $a['user_id']])->id,
            'planned_amount' => 500_000,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function envelope(): static
    {
        return $this->state(['mode' => PlanMode::Envelope, 'due_on' => null]);
    }

    public function forMonth(string $yearMonth): static
    {
        return $this->state(['month' => "{$yearMonth}-01"]);
    }
}
