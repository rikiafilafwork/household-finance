<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Jenis kategori. Transfer & adjustment tidak berkategori (chk_tx_category).
 */
enum CategoryKind: string
{
    use HasValues;

    case Income = 'income';
    case Expense = 'expense';
}
