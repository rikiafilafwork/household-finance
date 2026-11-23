<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope Keputusan #5: hanya baris milik user yang login.
 * Tanpa user login (console, job, tinker) query TIDAK mengembalikan apa pun: kode yang memang
 * bekerja lintas user (reconcile, scheduler) wajib melepas scope ini secara eksplisit
 * atau memakai `Model::ownedBy($user)`.
 *
 * @implements Scope<Model>
 */
final class OwnedByCurrentUser implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn('user_id');
        $id = Auth::id();

        if ($id === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($column, $id);
    }
}
