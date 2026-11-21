<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Jenis wallet.
 */
enum WalletType: string
{
    use HasValues;

    case Cash = 'cash';
    case Bank = 'bank';
    case Ewallet = 'ewallet';
}
