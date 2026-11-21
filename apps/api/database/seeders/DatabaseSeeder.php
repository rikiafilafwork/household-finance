<?php

namespace Database\Seeders;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Data dev lokal: satu owner + satu member dalam satu household.
     * Login: owner@example.com / member@example.com, password "password".
     */
    public function run(): void
    {
        $owner = User::factory()->create(['name' => 'Demo Owner', 'email' => 'owner@example.com']);
        $member = User::factory()->create(['name' => 'Demo Member', 'email' => 'member@example.com']);

        $household = Household::factory()->create(['name' => 'Keluarga Demo', 'created_by' => $owner->id]);
        $household->members()->attach($owner, ['role' => HouseholdRole::Owner, 'joined_at' => now()]);
        $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

        $this->call(DefaultCategorySeeder::class);
    }
}
