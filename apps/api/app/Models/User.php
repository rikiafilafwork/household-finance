<?php

namespace App\Models;

use App\Models\Scopes\OwnedByCurrentUser;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $timezone zona waktu IANA untuk tanggal lokal (Keputusan #12)
 */
#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Samakan dengan default kolom supaya user yang baru dibuat langsung punya zona waktu.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'timezone' => 'Asia/Jakarta',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Relasi ke data milik user. Lewati global scope: relasi ini sudah dibatasi user_id,
     * dan harus tetap jalan dari console/job tanpa sesi login.
     *
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class)->withoutGlobalScope(OwnedByCurrentUser::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->withoutGlobalScope(OwnedByCurrentUser::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->withoutGlobalScope(OwnedByCurrentUser::class);
    }
}
