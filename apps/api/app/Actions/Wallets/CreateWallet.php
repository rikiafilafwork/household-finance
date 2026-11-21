<?php

namespace App\Actions\Wallets;

use App\Enums\WalletType;
use App\Enums\WalletVisibility;
use App\Models\Household;
use App\Models\User;
use App\Models\Wallet;

class CreateWallet
{
    public function handle(
        Household $household,
        User $creator,
        string $name,
        WalletType $type,
        WalletVisibility $visibility,
        int $openingBalance = 0,
    ): Wallet {
        return Wallet::create([
            'household_id' => $household->getKey(),
            'owner_user_id' => $visibility === WalletVisibility::Private ? $creator->getKey() : null,
            'visibility' => $visibility,
            'type' => $type,
            'name' => $name,
            'opening_balance' => $openingBalance,
            'balance' => $openingBalance, // belum ada transaksi
        ]);
    }
}
