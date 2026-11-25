<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Siklus import mutasi (Keputusan #13): pending → parsing → ready → committing → committed,
 * atau failed kalau file tidak terbaca.
 */
enum ImportStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Parsing = 'parsing';
    case Ready = 'ready';
    case Committing = 'committing';
    case Committed = 'committed';
    case Failed = 'failed';
}
