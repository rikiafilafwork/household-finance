<?php

namespace App\Actions\Budgets;

use App\Enums\CategoryKind;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Household;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Set budget satu bulan sekaligus. Baris yang sudah ada dipertahankan (id & riwayat alert tetap),
 * hanya limitnya yang berubah; kategori yang hilang dari daftar dihapus budget-nya.
 */
class SetMonthlyBudgets
{
    /**
     * @param  array<int, int>  $limits  [category_id => limit_amount]
     */
    public function handle(Household $household, CarbonImmutable $month, array $limits): void
    {
        DB::transaction(function () use ($household, $month, $limits) {
            $existing = Budget::query()
                ->where('household_id', $household->getKey())
                ->where('month', $month->toDateString())
                ->lockForUpdate()
                ->pluck('category_id')->map(fn ($id) => (int) $id)->all();

            $this->assertCategories($household, array_keys($limits), $existing);

            Budget::query()
                ->where('household_id', $household->getKey())
                ->where('month', $month->toDateString())
                ->whereNotIn('category_id', array_keys($limits) ?: [0])
                ->delete();

            if ($limits === []) {
                return;
            }

            $now = now();
            Budget::upsert(
                array_map(fn (int $categoryId, int $limit) => [
                    'household_id' => $household->getKey(),
                    'category_id' => $categoryId,
                    'month' => $month->toDateString(),
                    'limit_amount' => $limit,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_keys($limits), $limits),
                ['household_id', 'category_id', 'month'],
                ['limit_amount', 'updated_at'],
            );
        });
    }

    /**
     * Kategori harus milik household, jenis pengeluaran, dan aktif. Kategori yang diarsip
     * boleh tetap dipertahankan kalau bulan itu sudah punya budget-nya (riwayat), tidak untuk baru.
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $alreadyBudgeted
     */
    private function assertCategories(Household $household, array $categoryIds, array $alreadyBudgeted): void
    {
        $categories = Category::query()
            ->where('household_id', $household->getKey())
            ->whereIn('id', $categoryIds ?: [0])
            ->get()->keyBy('id');

        $errors = [];
        foreach ($categoryIds as $index => $id) {
            $category = $categories->get($id);
            $field = "items.{$index}.category_id";

            if ($category === null) {
                $errors[$field] = 'Kategori tidak ditemukan.';
            } elseif ($category->kind !== CategoryKind::Expense) {
                $errors[$field] = 'Budget hanya untuk kategori pengeluaran.';
            } elseif ($category->archived_at !== null && ! in_array($id, $alreadyBudgeted, true)) {
                $errors[$field] = 'Kategori ini sudah diarsip.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
