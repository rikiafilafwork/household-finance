<?php

namespace App\Models;

use App\Enums\Frequency;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\PlanTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template rencana berulang (Keputusan #9). Bukan item bulan tertentu: `plan:generate` menurunkan
 * plan_items darinya, dan perubahan template tidak menyentuh item yang sudah dibuat.
 *
 * @property PlanItemType $type
 * @property PlanMode $mode
 * @property int $planned_amount
 * @property Frequency $frequency
 * @property int|null $day_of_month
 * @property int|null $month_of_year
 * @property bool $auto_post
 * @property bool $is_active
 */
#[Fillable([
    'user_id', 'type', 'mode', 'name', 'category_id', 'planned_amount', 'account_id',
    'frequency', 'day_of_month', 'month_of_year', 'auto_post', 'is_active',
])]
class PlanTemplate extends Model
{
    /** @use HasFactory<PlanTemplateFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @var array<string, string|bool>
     */
    protected $attributes = [
        'frequency' => 'monthly',
        'auto_post' => false,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PlanItemType::class,
            'mode' => PlanMode::class,
            'planned_amount' => 'integer',
            'frequency' => Frequency::class,
            'day_of_month' => 'integer',
            'month_of_year' => 'integer',
            'auto_post' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<PlanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PlanItem::class, 'template_id');
    }
}
