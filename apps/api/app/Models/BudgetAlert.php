<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu baris per (amplop, ambang). Kepemilikan lewat plan item (yang ber-scope user);
 * tidak ada endpoint yang membaca tabel ini langsung.
 *
 * @property int $threshold
 * @property int $spent_amount
 * @property Carbon $triggered_at
 */
#[Fillable(['plan_item_id', 'threshold', 'spent_amount', 'triggered_at'])]
class BudgetAlert extends Model
{
    public $timestamps = false;

    public const THRESHOLDS = [80, 100];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'spent_amount' => 'integer',
            'triggered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlanItem, $this>
     */
    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanItem::class);
    }
}
