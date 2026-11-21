<?php

namespace App\Http\Resources;

use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User + pivot keanggotaan (di-load lewat $household->members()).
 *
 * @mixin User
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HouseholdMember $membership */
        $membership = $this->resource->getRelation('membership');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $membership->role->value,
            'joined_at' => $membership->joined_at->toIso8601String(),
        ];
    }
}
