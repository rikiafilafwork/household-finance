<?php

namespace App\Http\Resources;

use App\Actions\Transfers\TransferPair;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TransferPair $resource
 */
class TransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pair = $this->resource;

        return [
            'transfer_group_id' => $pair->groupId(),
            'from_account_id' => $pair->out->account_id,
            'to_account_id' => $pair->in->account_id,
            'amount' => $pair->in->amount, // magnitudo
            'occurred_on' => $pair->out->occurred_on->toDateString(),
            'note' => $pair->out->note,
            'client_id' => $pair->out->client_id,
            'legs' => [
                'out' => new TransactionResource($pair->out),
                'in' => new TransactionResource($pair->in),
            ],
        ];
    }
}
