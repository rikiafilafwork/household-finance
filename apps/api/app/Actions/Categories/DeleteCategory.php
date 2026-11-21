<?php

namespace App\Actions\Categories;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hapus permanen hanya kalau kategori belum pernah dipakai (termasuk transaksi soft-deleted,
 * atau budget, karena FK & riwayat). Selain itu wajib arsip: transaksi lama tetap menunjuk kategori itu.
 */
class DeleteCategory
{
    public function handle(Category $category): void
    {
        DB::transaction(function () use ($category) {
            Category::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

            if ($this->inUse($category)) {
                throw ValidationException::withMessages([
                    'category' => 'Kategori ini sudah dipakai. Arsipkan saja supaya riwayatnya tetap utuh.',
                ]);
            }

            $category->delete();
        });
    }

    private function inUse(Category $category): bool
    {
        return Transaction::withTrashed()->where('category_id', $category->getKey())->exists()
            || Budget::query()->where('category_id', $category->getKey())->exists();
    }
}
