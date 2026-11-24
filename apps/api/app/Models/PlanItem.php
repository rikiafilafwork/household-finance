<?php

namespace App\Models;

use App\Enums\PlanItemSource;
use App\Enums\PlanItemType;
use App\Enums\PlanMode;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\PlanItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Item rencana bulanan (Keputusan #7). Status diturunkan, tidak disimpan:
 * done = punya transaksi (belum dihapus) dengan plan_item_id ini; skipped = skipped_at terisi.
 *
 * @property Carbon $month
 * @property PlanItemType $type
 * @property PlanMode $mode
 * @property int $planned_amount
 * @property Carbon|null $due_on
 * @property PlanItemSource $source
 * @property int $sort_order
 * @property Carbon|null $skipped_at
 * @property Carbon|null $auto_posted_at
 */
#[Fillable([
    'user_id', 'month', 'type', 'mode', 'name', 'category_id', 'planned_amount', 'account_id',
    'due_on', 'template_id', 'source', 'sort_order', 'skipped_at', 'auto_posted_at',
])]
class PlanItem extends Model
{
    /** @use HasFactory<PlanItemFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @var array<string, string|int>
     */
    protected $attributes = [
        'source' => 'manual',
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'type' => PlanItemType::class,
            'mode' => PlanMode::class,
            'planned_amount' => 'integer',
            'due_on' => 'date',
            'source' => PlanItemSource::class,
            'sort_order' => 'integer',
            'skipped_at' => 'datetime',
            'auto_posted_at' => 'datetime',
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
     * @return BelongsTo<PlanTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PlanTemplate::class, 'template_id');
    }

    /**
     * Transaksi centang (maks satu, dijaga uq_tx_plan_item). Soft-deleted tidak dihitung.
     *
     * @return HasOne<Transaction, $this>
     */
    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function isChecklist(): bool
    {
        return $this->mode === PlanMode::Checklist;
    }

    public function isSkipped(): bool
    {
        return $this->skipped_at !== null;
    }

    /** Sudah dicentang: ada transaksi aktif yang terhubung. Tidak bergantung pada sesi login. */
    public function isDone(): bool
    {
        return Transaction::ownedBy($this->user_id)->where('plan_item_id', $this->getKey())->exists();
    }

    /**
     * @param  Builder<PlanItem>  $query
     */
    public function scopeForMonth(Builder $query, string $month): void
    {
        $query->whereDate('month', $month);
    }
}
