<?php

namespace App\Actions\Accounts;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;

class CreateAccount
{
    public function handle(User $user, string $name, AccountType $type, int $openingBalance = 0, ?int $sortOrder = null, bool $isSavings = false): Account
    {
        return Account::create([
            'user_id' => $user->getKey(),
            'type' => $type,
            'is_savings' => $isSavings,
            'name' => $name,
            'opening_balance' => $openingBalance,
            'balance' => $openingBalance, // belum ada transaksi
            // Default: di urutan terakhir.
            'sort_order' => $sortOrder ?? ((int) Account::ownedBy($user)->max('sort_order') + 1),
        ]);
    }
}
