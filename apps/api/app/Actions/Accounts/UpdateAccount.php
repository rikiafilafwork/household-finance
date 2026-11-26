<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

class UpdateAccount
{
    /**
     * Koreksi opening_balance menggeser balance dengan selisih yang sama (akun dikunci),
     * sehingga invariant balance = opening_balance + SUM(amount) tetap terjaga.
     *
     * @param  array{name?: string, type?: string, is_savings?: bool, opening_balance?: int, sort_order?: int}  $data
     */
    public function handle(Account $account, array $data): Account
    {
        return DB::transaction(function () use ($account, $data) {
            /** @var Account $locked */
            $locked = Account::ownedBy($account->user_id)->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->is_savings && array_key_exists('is_savings', $data) && ! filter_var($data['is_savings'], FILTER_VALIDATE_BOOL)) {
                ArchiveAccount::ensureHoldsNoGoalMoney($locked, 'is_savings');
            }

            if (array_key_exists('opening_balance', $data)) {
                $delta = $data['opening_balance'] - $locked->opening_balance;
                $locked->balance += $delta;
            }

            $locked->fill($data)->save();

            return $locked;
        });
    }
}
