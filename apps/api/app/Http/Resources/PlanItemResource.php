<?php

namespace App\Http\Resources;

use App\Models\PlanItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlanItem
 */
class PlanItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transaction = $this->relationLoaded('transaction') ? $this->transaction : $this->transaction()->first();

        return [
            'id' => $this->id,
            'month' => $this->month->format('Y-m'),
            'type' => $this->type->value,
            'mode' => $this->mode->value,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'planned_amount' => $this->planned_amount, // integer rupiah, positif
            'account_id' => $this->account_id,
            'due_on' => $this->due_on?->toDateString(),
            'template_id' => $this->template_id,
            'source' => $this->source->value,
            'sort_order' => $this->sort_order,
            'skipped_at' => $this->skipped_at?->toIso8601String(),
            // Dicentang otomatis oleh plan:auto-post (sekali per item).
            'auto_posted_at' => $this->auto_posted_at?->toIso8601String(),
            // Diturunkan (Keputusan #7): done = ada transaksi terhubung; skipped = skipped_at terisi.
            'status' => $transaction !== null ? 'done' : ($this->skipped_at !== null ? 'skipped' : 'planned'),
            'transaction' => $transaction === null ? null : [
                'id' => $transaction->id,
                'account_id' => $transaction->account_id,
                'amount' => $transaction->amount,
                'occurred_on' => $transaction->occurred_on->toDateString(),
            ],
        ];
    }
}
