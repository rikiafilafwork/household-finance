<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Jobs\CheckSavingsOverdrawn;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `balance` hanya boleh berubah lewat Actions transaksi (dalam transaksi DB + lockForUpdate),
 * tidak pernah di-set langsung. Kebenarannya dibuktikan accounts:reconcile (Keputusan #3).
 *
 * @property AccountType $type
 * @property bool $is_savings
 * @property Carbon|null $savings_overdrawn_at
 * @property int $opening_balance
 * @property int $balance
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
#[Fillable(['user_id', 'type', 'is_savings', 'name', 'opening_balance', 'balance', 'sort_order', 'archived_at'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToUser, HasFactory;

    protected static function booted(): void
    {
        // Satu titik untuk semua jalur tulis saldo (transaksi, transfer, import, penyesuaian): Actions
        // memakai increment()/decrement() pada instance, yang menembakkan `updated`. Job baru jalan
        // setelah commit, jadi "Gunakan" (saldo & alokasi turun bersama) terlihat sehat.
        static::updated(fn (Account $a) => $a->is_savings && $a->wasChanged('balance')
            ? CheckSavingsOverdrawn::dispatch($a->getKey())
            : null);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'opening_balance' => 'integer',
            'balance' => 'integer',
            'is_savings' => 'boolean',
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
            'savings_overdrawn_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<SavingsGoal, $this>
     */
    public function savingsGoals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<Account>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
