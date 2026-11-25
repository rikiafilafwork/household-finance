<?php

namespace App\Http\Resources;

use App\Models\ImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ImportRow
 */
class ImportRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'row_number' => $this->row_number,
            'occurred_on' => $this->occurred_on,
            'amount' => $this->amount, // ber-tanda, integer rupiah
            'description' => $this->description,
            'status' => $this->status->value,
            'category_id' => $this->category_id,
            'transaction_id' => $this->transaction_id,
            'error' => $this->error,
            'raw' => $this->raw,
        ];
    }
}
