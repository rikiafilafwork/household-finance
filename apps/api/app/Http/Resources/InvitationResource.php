<?php

namespace App\Http\Resources;

use App\Models\HouseholdInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HouseholdInvitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            'invited_by' => $this->whenLoaded('inviter', fn () => new UserResource($this->inviter)),
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
