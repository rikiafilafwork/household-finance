<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Frekuensi transaksi berulang.
 */
enum Frequency: string
{
    use HasValues;

    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
