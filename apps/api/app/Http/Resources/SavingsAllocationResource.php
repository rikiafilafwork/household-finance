<?php

namespace App\Http\Resources;

use App\Models\SavingsAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavingsAllocation
 */
class SavingsAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'savings_goal_id' => $this->savings_goal_id,
            'amount' => $this->amount, // bertanda: positif = tambah, negatif = kurangi
            'occurred_on' => $this->occurred_on->toDateString(),
            'note' => $this->note,
            'transaction_id' => $this->transaction_id,
            'move_group_id' => $this->move_group_id,
            'client_id' => $this->client_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
