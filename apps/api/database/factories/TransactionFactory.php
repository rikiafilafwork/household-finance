<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * PERINGATAN: factory ini menulis langsung ke tabel dan TIDAK mengubah wallets.balance.
 * Pakai Actions untuk skenario saldo; factory hanya untuk seed data / test constraint & reconcile.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'household_id' => fn (array $attributes) => Wallet::findOrFail($attributes['wallet_id'])->household_id,
            'category_id' => null,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'amount' => -25_000,
            'occurred_on' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }

    public function forWallet(Wallet $wallet): static
    {
        return $this->state(['wallet_id' => $wallet->id, 'household_id' => $wallet->household_id]);
    }

    public function income(int $amount = 5_000_000): static
    {
        return $this->state(['type' => TransactionType::Income, 'amount' => abs($amount)]);
    }

    public function expense(int $amount = 25_000): static
    {
        return $this->state(['type' => TransactionType::Expense, 'amount' => -abs($amount)]);
    }

    /** Satu sisi transfer; amount ber-tanda (keluar negatif, masuk positif). */
    public function transferLeg(int $signedAmount, ?string $groupId = null): static
    {
        return $this->state([
            'type' => TransactionType::Transfer,
            'amount' => $signedAmount,
            'transfer_group_id' => $groupId ?? (string) Str::uuid(),
        ]);
    }

    public function adjustment(int $signedAmount): static
    {
        return $this->state(['type' => TransactionType::Adjustment, 'amount' => $signedAmount]);
    }

    public function pending(): static
    {
        return $this->state(['status' => TransactionStatus::Pending]);
    }
}
