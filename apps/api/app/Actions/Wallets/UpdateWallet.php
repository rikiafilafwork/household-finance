<?php

namespace App\Actions\Wallets;

use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class UpdateWallet
{
    /**
     * Koreksi opening_balance menggeser balance dengan selisih yang sama (wallet dikunci),
     * sehingga invariant balance = opening_balance + SUM(amount) tetap terjaga.
     *
     * @param  array{name?: string, type?: string, opening_balance?: int}  $data
     */
    public function handle(Wallet $wallet, array $data): Wallet
    {
        return DB::transaction(function () use ($wallet, $data) {
            /** @var Wallet $locked */
            $locked = Wallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            if (array_key_exists('opening_balance', $data)) {
                $delta = $data['opening_balance'] - $locked->opening_balance;
                $locked->balance += $delta;
            }

            $locked->fill($data)->save();

            return $locked;
        });
    }
}
