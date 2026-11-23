<?php

namespace Database\Seeders;

use App\Actions\Accounts\CreateAccount;
use App\Enums\AccountType;
use App\Models\User;
use App\Support\DefaultCategories;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Data dev lokal: satu akun pengguna demo dengan rekening, e-wallet, dan dompet.
     * Login: demo@example.com, password "password".
     */
    public function run(CreateAccount $createAccount): void
    {
        $user = User::factory()->create(['name' => 'Demo', 'email' => 'demo@example.com']);

        foreach ([
            ['BCA', AccountType::Bank, 5_000_000],
            ['Bank Jago', AccountType::Bank, 1_500_000],
            ['GoPay', AccountType::Ewallet, 250_000],
            ['Dompet', AccountType::Cash, 300_000],
        ] as $i => [$name, $type, $opening]) {
            $createAccount->handle($user, $name, $type, $opening, $i);
        }

        DefaultCategories::seedFor($user);
    }
}
