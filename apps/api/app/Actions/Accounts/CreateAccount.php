<?php

namespace App\Actions\Accounts;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;

class CreateAccount
{
    public function handle(User $user, string $name, AccountType $type, int $openingBalance = 0, ?int $sortOrder = null): Account
    {
        return Account::create([
            'user_id' => $user->getKey(),
            'type' => $type,
            'name' => $name,
            'opening_balance' => $openingBalance,
            'balance' => $openingBalance, // belum ada transaksi
            // Default: di urutan terakhir.
            'sort_order' => $sortOrder ?? ((int) Account::ownedBy($user)->max('sort_order') + 1),
        ]);
    }
}
