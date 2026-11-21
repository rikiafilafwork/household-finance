<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'currency', 'timezone', 'created_by'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<User, $this, HouseholdMember, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'household_members')
            ->using(HouseholdMember::class)
            ->as('membership')
            ->withPivot(['role', 'joined_at']);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<Budget, $this>
     */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /**
     * @return HasMany<Wallet, $this>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<HouseholdInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(HouseholdInvitation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function roleOf(User $user): ?HouseholdRole
    {
        $member = $this->members()->whereKey($user->getKey())->first();

        return $member?->membership->role;
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    public function isOwner(User $user): bool
    {
        return $this->roleOf($user) === HouseholdRole::Owner;
    }
}
