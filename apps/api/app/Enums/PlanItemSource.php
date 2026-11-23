<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Asal item: ditambah manual, dibuat dari template (plan:generate), atau disalin dari bulan lalu.
 */
enum PlanItemSource: string
{
    use HasValues;

    case Manual = 'manual';
    case Template = 'template';
    case Copied = 'copied';
}
