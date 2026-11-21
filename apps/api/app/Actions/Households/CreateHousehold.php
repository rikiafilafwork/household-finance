<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Support\Facades\DB;

/**
 * Household baru + pembuatnya jadi owner + kategori default, dalam satu transaksi DB.
 * Kalau dipanggil di dalam transaksi lain (register), ikut transaksi luar (savepoint).
 */
class CreateHousehold
{
    public function handle(User $owner, string $name, string $timezone = 'Asia/Jakarta'): Household
    {
        return DB::transaction(function () use ($owner, $name, $timezone) {
            $household = Household::create([
                'name' => $name,
                'timezone' => $timezone,
                'created_by' => $owner->getKey(),
            ]);

            $household->members()->attach($owner->getKey(), [
                'role' => HouseholdRole::Owner,
                'joined_at' => now(),
            ]);

            DefaultCategories::seedFor($household);

            return $household;
        });
    }
}
