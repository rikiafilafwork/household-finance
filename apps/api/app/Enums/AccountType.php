<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Jenis akun (Keputusan #6). Hanya `bank` yang punya import CSV mutasi.
 */
enum AccountType: string
{
    use HasValues;

    case Bank = 'bank';
    case Ewallet = 'ewallet';
    case Cash = 'cash';
}
