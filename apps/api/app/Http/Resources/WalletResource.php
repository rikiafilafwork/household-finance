<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'visibility' => $this->visibility->value,
            'is_mine' => $this->isPrivate() && $this->owner_user_id === $request->user()?->getAuthIdentifier(),
            'opening_balance' => $this->opening_balance, // integer rupiah
            'balance' => $this->balance,                 // integer rupiah
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
