<?php

namespace App\Http\Resources;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Household
 */
class HouseholdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'role' => $this->roleFor($request->user())?->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Peran user yang sedang login. Pakai pivot kalau household di-load lewat
     * $user->households() (relasi "membership"), supaya daftar household tanpa N+1.
     */
    private function roleFor(?User $user): ?HouseholdRole
    {
        $membership = $this->resource->relationLoaded('membership')
            ? $this->resource->getRelation('membership')
            : null;

        if ($membership instanceof HouseholdMember) {
            return $membership->role;
        }

        return $user ? $this->resource->roleOf($user) : null;
    }
}
