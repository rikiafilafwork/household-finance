<?php

namespace App\Http\Resources;

use App\Models\PlanTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlanTemplate
 */
class PlanTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'mode' => $this->mode->value,
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'planned_amount' => $this->planned_amount, // integer rupiah, positif
            'account_id' => $this->account_id,
            'frequency' => $this->frequency->value,
            'day_of_month' => $this->day_of_month,
            'month_of_year' => $this->month_of_year,
            'auto_post' => $this->auto_post,
            'is_active' => $this->is_active,
        ];
    }
}
