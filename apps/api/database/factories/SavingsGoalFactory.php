<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SavingsAllocation;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsGoal>
 */
class SavingsGoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => fn (array $a) => Account::factory()->savings()->create(['user_id' => $a['user_id']])->id,
            // Unik per user (uq_savings_goal_name).
            'name' => fake()->randomElement(['Dana Darurat', 'Liburan', 'Laptop', 'DP Rumah']).' '.fake()->unique()->numerify('####'),
            'target_amount' => 10_000_000,
            'allocated_amount' => 0,
            'sort_order' => 0,
        ];
    }

    /** Tujuan di rekening ini; user ikut rekening. */
    public function forAccount(Account $account): static
    {
        return $this->state(['user_id' => $account->user_id, 'account_id' => $account->id]);
    }

    /** Alokasi awal + satu baris log, supaya allocated_amount = Σ log tetap berlaku. */
    public function allocated(int $amount): static
    {
        return $this
            ->state(['allocated_amount' => $amount])
            ->afterCreating(function (SavingsGoal $goal) use ($amount) {
                if ($amount === 0) {
                    return;
                }

                SavingsAllocation::create([
                    'user_id' => $goal->user_id,
                    'savings_goal_id' => $goal->id,
                    'amount' => $amount,
                    'occurred_on' => now()->toDateString(),
                ]);
            });
    }
}
