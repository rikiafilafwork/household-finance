<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wallet_id' => $this->wallet_id,
            'category_id' => $this->category_id,
            // Ikut dikirim walau kategorinya sudah diarsip, supaya transaksi lama tetap tampil lengkap.
            'category' => new CategoryResource($this->whenLoaded('category')),
            'type' => $this->type->value,
            'status' => $this->status->value,
            'amount' => $this->amount, // integer ber-tanda, sudut pandang wallet
            'occurred_on' => $this->occurred_on->toDateString(),
            'note' => $this->note,
            'transfer_group_id' => $this->transfer_group_id,
            'client_id' => $this->client_id,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
