<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Jenis item rencana; sama dengan jenis kategorinya (pemasukan / pengeluaran).
 */
enum PlanItemType: string
{
    use HasValues;

    case Income = 'income';
    case Expense = 'expense';

    public function transactionType(): TransactionType
    {
        return TransactionType::from($this->value);
    }
}
