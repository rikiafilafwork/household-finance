<?php

namespace App\Models;

use App\Enums\CategoryKind;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property CategoryKind $kind
 * @property Carbon|null $archived_at
 */
#[Fillable(['user_id', 'kind', 'name', 'color', 'icon', 'archived_at'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CategoryKind::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
