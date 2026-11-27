<?php

namespace App\Actions\Savings;

use App\Actions\Accounts\LockAccounts;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveSavingsGoal
{
    public function __construct(private readonly LockAccounts $lockAccounts) {}

    /**
     * @param  array{account_id: int, name: string, target_amount: int, target_date?: ?string, sort_order?: int}  $data
     */
    public function create(User $user, array $data): SavingsGoal
    {
        return DB::transaction(function () use ($user, $data) {
            // Kunci rekening dulu (urutan kunci: rekening → tujuan) supaya tidak balapan dengan arsip/toggle rekening.
            $account = $this->lockAccounts->one($user, $data['account_id']);

            if (! $account->is_savings || $account->isArchived()) {
                throw ValidationException::withMessages(['account_id' => 'Rekening tabungan tidak ditemukan.']);
            }

            return SavingsGoal::create([
                'user_id' => $user->getKey(),
                'account_id' => $account->getKey(),
                'name' => $data['name'],
                'target_amount' => $data['target_amount'],
                'allocated_amount' => 0,
                'target_date' => $data['target_date'] ?? null,
                // Default: di urutan terakhir.
                'sort_order' => $data['sort_order'] ?? ((int) SavingsGoal::ownedBy($user)->max('sort_order') + 1),
            ]);
        });
    }

    /**
     * `account_id` tidak bisa diubah (ditolak di request).
     *
     * @param  array{name?: string, target_amount?: int, target_date?: ?string, sort_order?: int}  $data
     */
    public function update(SavingsGoal $goal, array $data): SavingsGoal
    {
        $goal->fill($data)->save();

        return $goal;
    }
}
