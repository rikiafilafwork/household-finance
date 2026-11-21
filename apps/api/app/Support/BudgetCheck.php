<?php

namespace App\Support;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Jobs\CheckBudgetThresholds;
use App\Models\Transaction;
use App\Models\Wallet;

/**
 * Kapan CheckBudgetThresholds perlu jalan: hanya expense `posted` berkategori di wallet shared
 * (wallet privat tidak menggerakkan budget). Job baru di-dispatch setelah commit.
 */
final class BudgetCheck
{
    public static function after(Transaction $transaction, Wallet $wallet): void
    {
        if ($transaction->type !== TransactionType::Expense
            || $transaction->status !== TransactionStatus::Posted
            || $transaction->category_id === null
            || $wallet->isPrivate()) {
            return;
        }

        CheckBudgetThresholds::dispatch(
            $transaction->household_id,
            $transaction->category_id,
            BudgetMonth::of($transaction->occurred_on)->toDateString(),
        );
    }
}
