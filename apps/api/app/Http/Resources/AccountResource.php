<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
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
            'opening_balance' => $this->opening_balance, // integer rupiah
            'balance' => $this->balance,                 // integer rupiah; negatif boleh, UI memberi peringatan
            'sort_order' => $this->sort_order,
            'archived_at' => $this->archived_at?->toIso8601String(),
        ];
    }
}
