<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pivot household_members (PK komposit, tanpa id & timestamps).
 *
 * @property HouseholdRole $role
 * @property Carbon $joined_at
 */
class HouseholdMember extends Pivot
{
    protected $table = 'household_members';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => HouseholdRole::class,
            'joined_at' => 'datetime',
        ];
    }
}
