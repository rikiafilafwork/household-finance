<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => AccountType::Bank,
            // Unik per user (uq_account_name).
            'name' => fake()->randomElement(['BCA', 'Mandiri', 'BRI', 'Bank Jago', 'GoPay', 'OVO']).' '.fake()->unique()->numerify('####'),
            'opening_balance' => 0,
            'balance' => 0,
            'sort_order' => 0,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    /** Saldo awal; balance ikut sama karena belum ada transaksi. */
    public function opening(int $amount): static
    {
        return $this->state(['opening_balance' => $amount, 'balance' => $amount]);
    }

    public function cash(): static
    {
        return $this->state(['type' => AccountType::Cash]);
    }

    public function ewallet(): static
    {
        return $this->state(['type' => AccountType::Ewallet]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
