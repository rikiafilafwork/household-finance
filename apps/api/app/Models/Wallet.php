<?php

namespace App\Models;

use App\Enums\WalletType;
use App\Enums\WalletVisibility;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `balance` hanya boleh berubah lewat Actions transaksi (dalam transaksi DB + lockForUpdate),
 * tidak pernah di-set langsung. Kebenarannya dibuktikan wallets:reconcile (Keputusan #3).
 *
 * @property WalletType $type
 * @property WalletVisibility $visibility
 * @property int $opening_balance
 * @property int $balance
 * @property Carbon|null $archived_at
 */
#[Fillable(['household_id', 'owner_user_id', 'visibility', 'type', 'name', 'opening_balance', 'balance', 'archived_at'])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'visibility' => WalletVisibility::class,
            'opening_balance' => 'integer',
            'balance' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isPrivate(): bool
    {
        return $this->visibility === WalletVisibility::Private;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<Wallet>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
