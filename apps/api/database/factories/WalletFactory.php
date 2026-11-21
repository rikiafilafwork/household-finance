<?php

namespace Database\Factories;

use App\Enums\WalletType;
use App\Enums\WalletVisibility;
use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'visibility' => WalletVisibility::Shared,
            'type' => WalletType::Bank,
            'name' => fake()->randomElement(['BCA', 'Mandiri', 'BRI', 'Dompet', 'GoPay', 'OVO']).' '.fake()->numerify('##'),
            'opening_balance' => 0,
            'balance' => 0,
        ];
    }

    /** Saldo awal; balance ikut sama karena belum ada transaksi. */
    public function opening(int $amount): static
    {
        return $this->state(['opening_balance' => $amount, 'balance' => $amount]);
    }

    public function privateTo(User $owner): static
    {
        return $this->state(['visibility' => WalletVisibility::Private, 'owner_user_id' => $owner->id]);
    }

    public function cash(): static
    {
        return $this->state(['type' => WalletType::Cash]);
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
