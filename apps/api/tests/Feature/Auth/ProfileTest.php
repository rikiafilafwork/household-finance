<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\withToken;

beforeEach(fn () => RateLimiter::clear('login'));

it('updates name and timezone', function () {
    $user = User::factory()->create();

    actingAs($user)->patchJson('/api/auth/me', ['name' => 'Riki', 'timezone' => 'Asia/Jayapura'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Riki')
        ->assertJsonPath('data.timezone', 'Asia/Jayapura');

    expect($user->fresh()?->timezone)->toBe('Asia/Jayapura');
});

it('validates the profile', function (array $payload, string $field) {
    actingAs(User::factory()->create())->patchJson('/api/auth/me', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown timezone' => [['timezone' => 'WIB'], 'timezone'],
    'empty timezone' => [['timezone' => ''], 'timezone'],
    'email change' => [['email' => 'lain@example.com'], 'email'],
    'password via profile' => [['password' => 'rahasia-baru-sekali'], 'password'],
]);

it('requires login for profile and password', function () {
    patchJson('/api/auth/me', ['name' => 'X'])->assertUnauthorized();
    putJson('/api/auth/password', [])->assertUnauthorized();
});

it('changes the password after checking the current one', function () {
    $user = User::factory()->create(['password' => 'rahasia-panjang']);

    actingAs($user)->putJson('/api/auth/password', [
        'current_password' => 'salah-sekali',
        'password' => 'rahasia-baru-sekali',
        'password_confirmation' => 'rahasia-baru-sekali',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    actingAs($user)->putJson('/api/auth/password', [
        'current_password' => 'rahasia-panjang',
        'password' => 'rahasia-baru-sekali',
        'password_confirmation' => 'rahasia-baru-sekali',
    ])->assertNoContent();

    expect(Hash::check('rahasia-baru-sekali', (string) $user->fresh()?->password))->toBeTrue();
});

it('revokes other mobile tokens but keeps the one making the request', function () {
    $user = User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);
    $issue = fn (string $device) => postJson('/api/auth/token', ['email' => 'riki@example.com', 'password' => 'rahasia-panjang', 'device_name' => $device])->json('token');
    $current = $issue('Pixel 8');
    $issue('HP lama');

    app('auth')->forgetGuards();
    withToken($current)->putJson('/api/auth/password', [
        'current_password' => 'rahasia-panjang',
        'password' => 'rahasia-baru-sekali',
        'password_confirmation' => 'rahasia-baru-sekali',
    ])->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['Pixel 8']);
});
