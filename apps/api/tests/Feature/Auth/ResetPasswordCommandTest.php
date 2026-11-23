<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\artisan;

it('sets a new password and revokes every session and token', function () {
    $user = User::factory()->create(['email' => 'riki@example.com']);
    $user->createToken('Pixel 8');
    DB::table('sessions')->insert(['id' => 'sesi-lama', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    DB::table('sessions')->insert(['id' => 'sesi-orang-lain', 'user_id' => User::factory()->create()->id, 'payload' => '', 'last_activity' => time()]);

    artisan('user:reset-password', ['email' => ' RIKI@example.com ', '--password' => 'rahasia-baru-sekali'])
        ->expectsOutputToContain('sudah diganti')
        ->assertSuccessful();

    expect(Hash::check('rahasia-baru-sekali', (string) $user->fresh()?->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['sesi-orang-lain']);
});

it('asks for the password interactively and requires confirmation', function () {
    $user = User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-lama-sekali']);

    artisan('user:reset-password', ['email' => 'riki@example.com'])
        ->expectsQuestion('Password baru', 'rahasia-baru-sekali')
        ->expectsQuestion('Ulangi password baru', 'beda-sama-sekali')
        ->expectsOutputToContain('tidak sama')
        ->assertFailed();

    artisan('user:reset-password', ['email' => 'riki@example.com'])
        ->expectsQuestion('Password baru', 'rahasia-baru-sekali')
        ->expectsQuestion('Ulangi password baru', 'rahasia-baru-sekali')
        ->assertSuccessful();

    expect(Hash::check('rahasia-baru-sekali', (string) $user->fresh()?->password))->toBeTrue();
});

it('fails for an unknown email or a weak password without changing anything', function () {
    $user = User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-lama-sekali']);

    artisan('user:reset-password', ['email' => 'siapa@example.com', '--password' => 'rahasia-baru-sekali'])
        ->expectsOutputToContain('tidak ditemukan')
        ->assertFailed();
    artisan('user:reset-password', ['email' => 'riki@example.com', '--password' => 'pendek'])->assertFailed();

    expect(Hash::check('rahasia-lama-sekali', (string) $user->fresh()?->password))->toBeTrue();
});

it('lets the user log in with the new password afterwards', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-lama-sekali']);
    artisan('user:reset-password', ['email' => 'riki@example.com', '--password' => 'rahasia-baru-sekali'])->assertSuccessful();

    spa()->csrf()->post('/api/auth/login', ['email' => 'riki@example.com', 'password' => 'rahasia-baru-sekali'])->assertOk();
});
