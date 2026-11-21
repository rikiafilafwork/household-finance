<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * pending tidak memengaruhi saldo, budget, maupun laporan sampai dikonfirmasi (Keputusan #6).
 */
enum TransactionStatus: string
{
    use HasValues;

    case Posted = 'posted';
    case Pending = 'pending';
}
