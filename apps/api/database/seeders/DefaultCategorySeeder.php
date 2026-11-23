<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Database\Seeder;

/**
 * Pastikan setiap user punya kategori bawaan (idempoten).
 */
class DefaultCategorySeeder extends Seeder
{
    public function run(): void
    {
        User::query()->each(fn (User $user) => DefaultCategories::seedFor($user));
    }
}
