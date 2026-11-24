<?php

namespace Database\Factories;

use App\Enums\Frequency;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Models\Category;
use App\Models\PlanTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanTemplate>
 */
class PlanTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => PlanItemType::Expense,
            'mode' => PlanMode::Checklist,
            'name' => fake()->randomElement(['Listrik', 'Internet', 'Cicilan motor', 'Pulsa']),
            'category_id' => fn (array $a) => Category::factory()->create(['user_id' => $a['user_id']])->id,
            'planned_amount' => 500_000,
            'frequency' => Frequency::Monthly,
            'day_of_month' => 5,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function envelope(): static
    {
        return $this->state(['mode' => PlanMode::Envelope, 'day_of_month' => null, 'auto_post' => false]);
    }

    public function yearly(int $month): static
    {
        return $this->state(['frequency' => Frequency::Yearly, 'month_of_year' => $month]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
