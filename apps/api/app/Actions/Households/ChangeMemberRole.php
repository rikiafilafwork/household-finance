<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeMemberRole
{
    public function __construct(private readonly EnsureOwnerRemains $ensureOwnerRemains) {}

    public function handle(Household $household, User $member, HouseholdRole $role): void
    {
        DB::transaction(function () use ($household, $member, $role) {
            $this->ensureOwnerRemains->lockAndCheck($household, $member, $role === HouseholdRole::Owner);

            $household->members()->updateExistingPivot($member->getKey(), ['role' => $role]);
        });
    }
}
