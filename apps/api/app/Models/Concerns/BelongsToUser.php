<?php

namespace App\Models\Concerns;

use App\Models\Scopes\OwnedByCurrentUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Data milik satu user (Keputusan #5):
 *  - global scope `user_id = auth()->id()` (tanpa login → kosong), termasuk route model binding;
 *  - `user_id` diisi otomatis dari user login saat create kalau belum diisi.
 *
 * Actions menerima User eksplisit dan memakai `ownedBy($user)`, supaya bisa dipanggil dari
 * console/job/worker yang tidak punya sesi login.
 *
 * @property int $user_id
 */
trait BelongsToUser
{
    public static function bootBelongsToUser(): void
    {
        static::addGlobalScope(new OwnedByCurrentUser);

        static::creating(function (self $model) {
            if ($model->getAttribute('user_id') === null && Auth::id() !== null) {
                $model->setAttribute('user_id', Auth::id());
            }
        });
    }

    /**
     * Query milik user tertentu, tidak bergantung pada sesi login.
     *
     * @return Builder<static>
     */
    public static function ownedBy(User|int $user): Builder
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        $query = static::query()->withoutGlobalScope(OwnedByCurrentUser::class);

        return $query->where($query->qualifyColumn('user_id'), $id);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
