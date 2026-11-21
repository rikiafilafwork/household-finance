<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * shared terlihat semua anggota; private hanya pemiliknya, termasuk dari owner household (ADR-005).
 */
enum WalletVisibility: string
{
    use HasValues;

    case Shared = 'shared';
    case Private = 'private';
}
