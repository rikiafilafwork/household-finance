<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Peran anggota di household (Architecture.md Keputusan #5).
 */
enum HouseholdRole: string
{
    use HasValues;

    case Owner = 'owner';
    case Member = 'member';
}
