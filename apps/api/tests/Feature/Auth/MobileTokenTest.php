<?php

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

beforeEach(fn () => RateLimiter::clear('login'));

/**
 * Request Bearer dari "HP": tanpa Origin (bukan SPA stateful), guard di-reset tiap request
 * supaya token benar-benar diperiksa ulang ke database.
 */
function bearer(string $token): TestCase
{
    app('auth')->forgetGuards();

    return withToken($token);
}

function issueToken(string $email = 'riki@example.com', string $password = 'rahasia-panjang'): string
{
    return postJson('/api/auth/token', [
        'email' => $email,
        'password' => $password,
        'device_name' => 'Pixel 8',
    ])->assertCreated()->json('token');
}

it('issues a personal access token for valid credentials', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);

    postJson('/api/auth/token', [
        'email' => 'Riki@Example.com',
        'password' => 'rahasia-panjang',
        'device_name' => 'Pixel 8',
    ])
        ->assertCreated()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
        ->assertJsonPath('user.email', 'riki@example.com');

    expect(PersonalAccessToken::sole()->name)->toBe('Pixel 8');
});

it('authenticates API calls with the bearer token', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);
    $token = issueToken();

    bearer($token)->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'riki@example.com');

    expect(PersonalAccessToken::sole()->last_used_at)->not->toBeNull();
});

it('revokes only the current token', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);
    $phone = issueToken();
    $tablet = issueToken();

    bearer($phone)->deleteJson('/api/auth/token')->assertNoContent();

    bearer($phone)->getJson('/api/auth/me')->assertUnauthorized();
    bearer($tablet)->getJson('/api/auth/me')->assertOk();
});

it('rejects invalid credentials and missing device name', function (array $payload, string $field) {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);

    postJson('/api/auth/token', array_merge([
        'email' => 'riki@example.com',
        'password' => 'rahasia-panjang',
        'device_name' => 'Pixel 8',
    ], $payload))->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(PersonalAccessToken::count())->toBe(0);
})->with([
    'wrong password' => [['password' => 'salah'], 'email'],
    'unknown email' => [['email' => 'nobody@example.com'], 'email'],
    'missing device name' => [['device_name' => ''], 'device_name'],
]);

it('rejects a garbage token', function () {
    bearer('1|bukan-token-asli')->getJson('/api/auth/me')->assertUnauthorized();
    getJson('/api/auth/me')->assertUnauthorized();
});

it('refuses to revoke when the request is not token-authenticated', function () {
    $user = User::factory()->create();

    // Sesi web (actingAs) memakai TransientToken, bukan personal access token.
    actingAs($user, 'web');
    deleteJson('/api/auth/token')->assertStatus(400);
});

it('rate limits token requests', function () {
    User::factory()->create(['email' => 'riki@example.com', 'password' => 'rahasia-panjang']);

    foreach (range(1, 5) as $_) {
        postJson('/api/auth/token', ['email' => 'riki@example.com', 'password' => 'salah', 'device_name' => 'x'])
            ->assertUnprocessable();
    }

    postJson('/api/auth/token', ['email' => 'riki@example.com', 'password' => 'rahasia-panjang', 'device_name' => 'x'])
        ->assertTooManyRequests();
});
