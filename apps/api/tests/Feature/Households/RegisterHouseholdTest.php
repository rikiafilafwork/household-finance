<?php

use App\Actions\Households\CreateHousehold;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use App\Support\DefaultCategories;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutExceptionHandling;
use function Pest\Laravel\withToken;

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Riki',
        'email' => 'riki@example.com',
        'password' => 'rahasia-panjang',
        'password_confirmation' => 'rahasia-panjang',
    ], $overrides);
}

it('creates the first household, owner membership and default categories on register', function () {
    spa()->csrf()->post('/api/auth/register', registerPayload())->assertCreated();

    $user = User::where('email', 'riki@example.com')->sole();
    $household = $user->households()->sole();

    expect($household->name)->toBe('Keluarga Riki')
        ->and($household->timezone)->toBe('Asia/Jakarta')
        ->and($household->created_by)->toBe($user->id)
        ->and($household->roleOf($user))->toBe(HouseholdRole::Owner)
        ->and($household->categories()->count())->toBe(count(DefaultCategories::all()));
});

it('uses the household name given at registration', function () {
    spa()->csrf()->post('/api/auth/register', registerPayload(['household_name' => 'Rumah Bandung']))->assertCreated();

    expect(Household::sole()->name)->toBe('Rumah Bandung');
});

it('rolls back the user when household creation fails', function () {
    app()->instance(CreateHousehold::class, new class extends CreateHousehold
    {
        public function handle(User $owner, string $name, string $timezone = 'Asia/Jakarta'): Household
        {
            throw new RuntimeException('gagal di tengah jalan');
        }
    });

    withoutExceptionHandling();
    expect(fn () => spa()->csrf()->post('/api/auth/register', registerPayload()))
        ->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0)
        ->and(Household::count())->toBe(0);
});

it('lists only the households the user belongs to, with their role', function () {
    $user = User::factory()->create();
    $own = Household::factory()->withOwner($user)->create(['name' => 'A Rumah Sendiri']);
    $joined = Household::factory()->withOwner()->create(['name' => 'B Rumah Orang Tua']);
    $joined->members()->attach($user, ['role' => HouseholdRole::Member, 'joined_at' => now()]);
    Household::factory()->withOwner()->create(['name' => 'C Bukan Urusan Saya']);

    actingAs($user)->getJson('/api/households')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $own->id)
        ->assertJsonPath('data.0.role', 'owner')
        ->assertJsonPath('data.1.id', $joined->id)
        ->assertJsonPath('data.1.role', 'member');
});

it('creates an additional household for the current user', function () {
    $user = User::factory()->create();

    actingAs($user)->postJson('/api/households', ['name' => 'Rumah Kedua', 'timezone' => 'Asia/Makassar'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Rumah Kedua')
        ->assertJsonPath('data.timezone', 'Asia/Makassar')
        ->assertJsonPath('data.role', 'owner');

    expect($user->households()->sole()->categories()->count())->toBe(count(DefaultCategories::all()));
});

it('validates new household input', function (array $payload, string $field) {
    actingAs(User::factory()->create())->postJson('/api/households', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'invalid timezone' => [['name' => 'X', 'timezone' => 'Mars/Olympus'], 'timezone'],
]);

it('shows a household to members and forbids outsiders', function () {
    $member = User::factory()->create();
    $household = Household::factory()->withOwner()->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

    actingAs($member)->getJson("/api/households/{$household->id}")
        ->assertOk()
        ->assertJsonPath('data.role', 'member');

    actingAs(User::factory()->create())->getJson("/api/households/{$household->id}")->assertForbidden();
});

it('requires authentication', function () {
    getJson('/api/households')->assertUnauthorized();
    postJson('/api/households', ['name' => 'X'])->assertUnauthorized();
});

it('lists households with a mobile bearer token', function () {
    spa()->csrf()->post('/api/auth/register', registerPayload())->assertCreated();
    app('auth')->forgetGuards();

    $token = postJson('/api/auth/token', [
        'email' => 'riki@example.com',
        'password' => 'rahasia-panjang',
        'device_name' => 'Pixel 8',
    ])->json('token');

    app('auth')->forgetGuards();
    withToken($token)->getJson('/api/households')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Keluarga Riki');
});
