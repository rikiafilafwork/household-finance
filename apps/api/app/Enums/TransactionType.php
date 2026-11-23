<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Tanda amount mengikuti sudut pandang akun (Keputusan #2):
 * income positif, expense negatif, transfer keluar negatif / masuk positif, adjustment bebas.
 * Ditegakkan juga oleh CHECK constraint `chk_tx_sign` di database.
 */
enum TransactionType: string
{
    use HasValues;

    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';

    /** Tipe yang masuk laporan pemasukan/pengeluaran dan wajib punya kategori. */
    public function isCategorized(): bool
    {
        return $this === self::Income || $this === self::Expense;
    }
}
