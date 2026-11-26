<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kata kunci deskripsi mutasi → kategori (lihat App\Imports\CategorySuggester).
 *
 * @property string $keyword
 * @property int $category_id
 */
#[Fillable(['user_id', 'keyword', 'category_id'])]
class CategoryRule extends Model
{
    use BelongsToUser;

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
