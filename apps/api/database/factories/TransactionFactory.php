<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * PERINGATAN: factory ini menulis langsung ke tabel dan TIDAK mengubah accounts.balance.
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
            'account_id' => Account::factory(),
            'user_id' => fn (array $attributes) => Account::query()->withoutGlobalScopes()->findOrFail($attributes['account_id'])->user_id,
            'category_id' => null,
            'type' => TransactionType::Expense,
            'amount' => -25_000,
            'occurred_on' => now()->toDateString(),
        ];
    }

    public function forAccount(Account $account): static
    {
        return $this->state(['account_id' => $account->id, 'user_id' => $account->user_id]);
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
}
