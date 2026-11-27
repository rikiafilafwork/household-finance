<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\SavingsGoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tujuan tabungan di satu rekening tabungan. `allocated_amount` = Σ log alokasi dan hanya
 * berubah lewat Actions (transaksi DB + lock akun), tidak pernah di-set langsung.
 *
 * @property int $account_id
 * @property int $target_amount
 * @property int $allocated_amount
 * @property Carbon|null $target_date
 * @property Carbon|null $achieved_at
 * @property Carbon|null $archived_at
 * @property int $sort_order
 */
#[Fillable([
    'user_id', 'account_id', 'name', 'target_amount', 'allocated_amount', 'target_date',
    'achieved_at', 'archived_at', 'sort_order',
])]
class SavingsGoal extends Model
{
    /** @use HasFactory<SavingsGoalFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'allocated_amount' => 'integer',
            'target_date' => 'date',
            'achieved_at' => 'datetime',
            'archived_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<SavingsAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SavingsAllocation::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<SavingsGoal>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
