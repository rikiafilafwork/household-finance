<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Status baris staging import (Keputusan #13):
 *  - new: belum ada di akun ini → bisa di-commit;
 *  - duplicate: fingerprint sudah jadi transaksi (impor ulang / file tumpang tindih);
 *  - possible_duplicate: ada transaksi non-import dengan amount sama, tanggal ±1 hari (mungkin dicatat manual);
 *  - invalid: tanggal/nominal tak terbaca;
 *  - committed / skipped: hasil commit (#54).
 */
enum ImportRowStatus: string
{
    use HasValues;

    case New = 'new';
    case Duplicate = 'duplicate';
    case PossibleDuplicate = 'possible_duplicate';
    case Invalid = 'invalid';
    case Committed = 'committed';
    case Skipped = 'skipped';
}
