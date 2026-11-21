<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Support\DefaultCategories;
use Illuminate\Database\Seeder;

/**
 * Pastikan setiap household punya kategori bawaan (idempoten).
 */
class DefaultCategorySeeder extends Seeder
{
    public function run(): void
    {
        Household::query()->each(fn (Household $household) => DefaultCategories::seedFor($household));
    }
}
