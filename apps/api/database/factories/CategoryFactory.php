<?php

namespace Database\Factories;

use App\Enums\CategoryKind;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => CategoryKind::Expense,
            'name' => fake()->unique()->words(2, true),
            'color' => fake()->hexColor(),
            'icon' => 'tag',
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function income(): static
    {
        return $this->state(['kind' => CategoryKind::Income]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
