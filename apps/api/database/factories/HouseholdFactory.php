<?php

namespace Database\Factories;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Keluarga '.fake()->lastName(),
            'currency' => 'IDR',
            'timezone' => 'Asia/Jakarta',
            'created_by' => User::factory(),
        ];
    }

    /**
     * Pembuat household otomatis jadi owner.
     */
    public function withOwner(?User $owner = null): static
    {
        return $this->state(fn () => $owner ? ['created_by' => $owner->id] : [])
            ->afterCreating(function (Household $household) {
                $household->members()->attach($household->created_by, [
                    'role' => HouseholdRole::Owner,
                    'joined_at' => now(),
                ]);
            });
    }
}
