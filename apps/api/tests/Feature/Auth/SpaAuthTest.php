<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => RateLimiter::clear('login'));

it('issues the CSRF cookie', function () {
    spa()->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('rejects state-changing requests without a CSRF token', function () {
    spa()->post('/api/auth/login', ['email' => 'a@example.com', 'password' => 'x'])
        ->assertStatus(419);
});

it('registers a user and starts a session', function () {
    $browser = spa()->csrf();

    $browser->post('/api/auth/register', [
        'name' => 'Riki',
        'email' => '  Riki@Example.COM ',
        'password' => 'rahasia-panjang',
        'password_confirmation' => 'rahasia-panjang',
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'riki@example.com')
        ->assertJsonMissingPath('data.password');

    $browser->get('/api/auth/me')->assertOk()->assertJsonPath('data.name', 'Riki');
});

it('returns the timezone on /auth/me and accepts one at registration', function () {
    $browser = spa()->csrf();

    $browser->post('/api/auth/register', [
        'name' => 'Riki', 'email' => 'riki@example.com',
        'password' => 'rahasia-panjang', 'password_confirmation' => 'rahasia-panjang',
        'timezone' => 'Asia/Makassar',
    ])->assertCreated()->assertJsonPath('data.timezone', 'Asia/Makassar');

    $browser->get('/api/auth/me')->assertJsonPath('data.timezone', 'Asia/Makassar');
});

it('defaults the timezone to Asia/Jakarta', function () {
    spa()->csrf()->post('/api/auth/register', [
        'name' => 'Riki', 'email' => 'riki@example.com',
        'password' => 'rahasia-panjang', 'password_confirmation' => 'rahasia-panjang',
    ])->assertCreated()->assertJsonPath('data.timezone', 'Asia/Jakarta');
});

it('seeds the default categories for the new user only (no household)', function () {
    $browser = spa()->csrf();
    $browser->post('/api/auth/register', [
        'name' => 'Riki', 'email' => 'riki@example.com',
        'password' => 'rahasia-panjang', 'password_confirmation' => 'rahasia-panjang',
    ])->assertCreated();

    $browser->get('/api/categories?kind=expense')->assertOk()
        ->assertJsonPath('data.*.name', ['Belanja', 'Hiburan', 'Kesehatan', 'Lainnya', 'Makan', 'Pendidikan', 'Tagihan', 'Transport']);
    $browser->get('/api/categories?kind=income')->assertOk()
        ->assertJsonPath('data.*.name', ['Freelance', 'Gaji', 'Lainnya']);
    expect(Schema::hasTable('households'))->toBeFalse();
});

it('rejects registration when REGISTRATION_ENABLED is false', function () {
    config(['auth.registration_enabled' => false]);

    spa()->csrf()->post('/api/auth/register', [
        'name' => 'Riki', 'email' => 'riki@example.com',
        'password' => 'rahasia-panjang', 'password_confirmation' => 'rahasia-panjang',
    ])->assertForbidden()->assertJsonPath('message', 'Pendaftaran akun baru sedang ditutup.');

    expect(User::count())->toBe(0);
});

it('still lets existing users log in when registration is closed', function () {
    config(['auth.registration_enabled' => false]);
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);

    spa()->csrf()->post('/api/auth/login', ['email' => 'riki@example.com', 'password' => 'rahasia-panjang'])->assertOk();
});

it('validates registration input', function (array $payload, string $field) {
    User::factory()->create(['email' => 'taken@example.com']);

    spa()->csrf()->post('/api/auth/register', array_merge([
        'name' => 'X',
        'email' => 'new@example.com',
        'password' => 'rahasia-panjang',
        'password_confirmation' => 'rahasia-panjang',
    ], $payload))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'email taken (case-insensitive)' => [['email' => 'TAKEN@example.com'], 'email'],
    'invalid email' => [['email' => 'bukan-email'], 'email'],
    'password too short' => [['password' => 'pendek', 'password_confirmation' => 'pendek'], 'password'],
    'password not confirmed' => [['password_confirmation' => 'lain-lagi-ya'], 'password'],
    'missing name' => [['name' => ''], 'name'],
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
]);

it('logs in with valid credentials, case-insensitive email', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);
    $browser = spa()->csrf();

    $browser->post('/api/auth/login', ['email' => 'RIKI@example.com', 'password' => 'rahasia-panjang'])
        ->assertOk()
        ->assertJsonPath('data.email', 'riki@example.com');

    $browser->get('/api/auth/me')->assertOk();
});

it('keeps another browser logged out', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);
    spa()->csrf()->post('/api/auth/login', ['email' => 'riki@example.com', 'password' => 'rahasia-panjang'])->assertOk();

    // Browser lain tanpa cookie session tidak ikut login.
    spa()->get('/api/auth/me')->assertUnauthorized();
});

it('rejects a wrong password with a validation error', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);
    $browser = spa()->csrf();

    $browser->post('/api/auth/login', ['email' => 'riki@example.com', 'password' => 'salah'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $browser->get('/api/auth/me')->assertUnauthorized();
});

it('logs out and ends the session', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);
    $browser = spa()->csrf();
    $browser->post('/api/auth/login', ['email' => 'riki@example.com', 'password' => 'rahasia-panjang'])->assertOk();

    $browser->post('/api/auth/logout')->assertNoContent();

    $browser->get('/api/auth/me')->assertUnauthorized();
});

it('rejects protected endpoints without a session', function () {
    $browser = spa()->csrf();

    $browser->get('/api/auth/me')->assertUnauthorized();
    $browser->post('/api/auth/logout')->assertUnauthorized();
});

it('rate limits login attempts per email and IP', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);
    $browser = spa()->csrf();

    foreach (range(1, 5) as $_) {
        $browser->post('/api/auth/login', ['email' => 'riki@example.com', 'password' => 'salah'])->assertUnprocessable();
    }

    // Percobaan ke-6, bahkan dengan password benar, ditolak.
    $browser->post('/api/auth/login', ['email' => 'riki@example.com', 'password' => 'rahasia-panjang'])
        ->assertTooManyRequests();
});
