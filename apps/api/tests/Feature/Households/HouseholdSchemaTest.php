<?php

use App\Enums\CategoryKind;
use App\Enums\HouseholdRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\User;
use App\Support\DefaultCategories;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\seed;

it('links members through the pivot with a role', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

    expect($household->roleOf($owner))->toBe(HouseholdRole::Owner)
        ->and($household->roleOf($member))->toBe(HouseholdRole::Member)
        ->and($household->isOwner($member))->toBeFalse()
        ->and($household->hasMember(User::factory()->create()))->toBeFalse()
        ->and($member->households()->first()?->membership->role)->toBe(HouseholdRole::Member);
});

it('lets a user belong to several households', function () {
    $user = User::factory()->create();
    Household::factory()->count(2)->withOwner($user)->create();

    expect($user->households)->toHaveCount(2);
});

it('rejects duplicate membership', function () {
    $household = Household::factory()->withOwner()->create();

    $household->members()->attach($household->created_by, ['role' => HouseholdRole::Member, 'joined_at' => now()]);
})->throws(QueryException::class);

it('rejects duplicate category names per household and kind', function () {
    $household = Household::factory()->create();
    Category::factory()->for($household)->create(['name' => 'Makan']);

    Category::factory()->for($household)->create(['name' => 'Makan']);
})->throws(QueryException::class);

it('allows the same category name for different kinds', function () {
    $household = Household::factory()->create();
    Category::factory()->for($household)->create(['name' => 'Lainnya']);
    Category::factory()->for($household)->income()->create(['name' => 'Lainnya']);

    expect($household->categories)->toHaveCount(2);
});

it('seeds default categories idempotently', function () {
    $household = Household::factory()->create();

    DefaultCategories::seedFor($household);
    DefaultCategories::seedFor($household);

    $expected = count(DefaultCategories::all());
    expect($household->categories()->count())->toBe($expected)
        ->and($household->categories()->where('kind', CategoryKind::Income)->pluck('name')->all())
        ->toContain('Gaji');
});

it('cascades members and categories when a household is deleted', function () {
    $household = Household::factory()->withOwner()->create();
    DefaultCategories::seedFor($household);

    $household->delete();

    assertDatabaseCount('household_members', 0);
    assertDatabaseCount('categories', 0);
});

it('runs the development seeder', function () {
    seed(DatabaseSeeder::class);

    $household = Household::firstOrFail();
    expect($household->members)->toHaveCount(2)
        ->and($household->categories()->count())->toBe(count(DefaultCategories::all()));
});
