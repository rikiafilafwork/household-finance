<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * User untuk `make load-test` (k6). Idempoten. Hanya untuk lingkungan dev.
 */
class LoadTestUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'loadtest@example.com'],
            ['name' => 'Load Test', 'password' => 'rahasia-panjang'],
        );
    }
}
