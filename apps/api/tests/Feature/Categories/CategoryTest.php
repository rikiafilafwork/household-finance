<?php

use App\Enums\CategoryKind;
use App\Enums\HouseholdRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travel;

/**
 * @return array{0: Household, 1: User, 2: User}
 */
function categoryHousehold(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

    return [$household, $owner, $member];
}

function categoriesUrl(Household $household, ?Category $category = null, string $suffix = ''): string
{
    return "/api/households/{$household->id}/categories".($category ? "/{$category->id}" : '').$suffix;
}

describe('creating', function () {
    it('lets the owner create a category with color and icon', function () {
        [$household, $owner] = categoryHousehold();

        actingAs($owner)->postJson(categoriesUrl($household), [
            'kind' => 'expense',
            'name' => '  Jajan Anak  ',
            'color' => '#F97316',
            'icon' => 'ice-cream-cone',
        ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'expense')
            ->assertJsonPath('data.name', 'Jajan Anak')
            ->assertJsonPath('data.color', '#F97316')
            ->assertJsonPath('data.icon', 'ice-cream-cone')
            ->assertJsonPath('data.archived_at', null);

        expect(Category::sole()->household_id)->toBe($household->id);
    });

    it('forbids members from creating categories', function () {
        [$household, , $member] = categoryHousehold();

        actingAs($member)->postJson(categoriesUrl($household), ['kind' => 'expense', 'name' => 'X'])->assertForbidden();
        expect(Category::count())->toBe(0);
    });

    it('forbids outsiders', function () {
        [$household] = categoryHousehold();

        actingAs(User::factory()->create())->getJson(categoriesUrl($household))->assertForbidden();
    });

    it('rejects a duplicate name within the same kind but allows it across kinds and households', function () {
        [$household, $owner] = categoryHousehold();
        Category::factory()->for($household)->create(['kind' => CategoryKind::Expense, 'name' => 'Lainnya']);

        actingAs($owner)->postJson(categoriesUrl($household), ['kind' => 'expense', 'name' => 'Lainnya'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'sudah ada']);

        actingAs($owner)->postJson(categoriesUrl($household), ['kind' => 'income', 'name' => 'Lainnya'])->assertCreated();

        $other = Household::factory()->withOwner($owner)->create();
        actingAs($owner)->postJson(categoriesUrl($other), ['kind' => 'expense', 'name' => 'Lainnya'])->assertCreated();
    });

    it('validates kind, color, and icon', function (array $payload, string $field) {
        [$household, $owner] = categoryHousehold();

        actingAs($owner)->postJson(categoriesUrl($household), [...['kind' => 'expense', 'name' => 'Ok'], ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'kind transfer' => [['kind' => 'transfer'], 'kind'],
        'tanpa nama' => [['name' => ''], 'name'],
        'nama > 60' => [['name' => str_repeat('a', 61)], 'name'],
        'warna bukan hex' => [['color' => 'orange'], 'color'],
        'warna 3 digit' => [['color' => '#FFF'], 'color'],
        'ikon berspasi' => [['icon' => 'shopping basket'], 'icon'],
        'ikon huruf besar' => [['icon' => 'Car'], 'icon'],
    ]);
});

describe('listing', function () {
    it('lists active categories by default, archived ones on request, filtered by kind', function () {
        [$household, , $member] = categoryHousehold();
        Category::factory()->for($household)->create(['name' => 'Makan']);
        Category::factory()->for($household)->archived()->create(['name' => 'Rokok']);
        Category::factory()->for($household)->income()->create(['name' => 'Gaji']);
        Category::factory()->create(['name' => 'Household lain']);

        actingAs($member)->getJson(categoriesUrl($household))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.*.name', ['Gaji', 'Makan']); // income dulu (urutan enum), lalu nama

        actingAs($member)->getJson(categoriesUrl($household).'?include_archived=1&kind=expense')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Makan', 'Rokok']);
    });

    it('returns 404 for a category of another household', function () {
        [$household, $owner] = categoryHousehold();
        $foreign = Category::factory()->create();

        actingAs($owner)->getJson(categoriesUrl($household, $foreign))->assertNotFound();
        actingAs($owner)->patchJson(categoriesUrl($household, $foreign), ['name' => 'X'])->assertNotFound();
    });
});

describe('updating', function () {
    it('lets the owner rename and recolor', function () {
        [$household, $owner] = categoryHousehold();
        $category = Category::factory()->for($household)->create(['name' => 'Makan']);

        actingAs($owner)->patchJson(categoriesUrl($household, $category), ['name' => 'Makan & Minum', 'color' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Makan & Minum')
            ->assertJsonPath('data.color', null);
    });

    it('allows keeping its own name but rejects another category name', function () {
        [$household, $owner] = categoryHousehold();
        $category = Category::factory()->for($household)->create(['name' => 'Makan']);
        Category::factory()->for($household)->create(['name' => 'Transport']);

        actingAs($owner)->patchJson(categoriesUrl($household, $category), ['name' => 'Makan'])->assertOk();
        actingAs($owner)->patchJson(categoriesUrl($household, $category), ['name' => 'Transport'])
            ->assertJsonValidationErrors('name');
    });

    it('refuses to change kind', function () {
        [$household, $owner] = categoryHousehold();
        $category = Category::factory()->for($household)->create();

        actingAs($owner)->patchJson(categoriesUrl($household, $category), ['kind' => 'income'])
            ->assertJsonValidationErrors('kind');
        expect($category->fresh()->kind)->toBe(CategoryKind::Expense);
    });

    it('forbids members from updating, archiving, or deleting', function () {
        [$household, , $member] = categoryHousehold();
        $category = Category::factory()->for($household)->create();

        actingAs($member)->patchJson(categoriesUrl($household, $category), ['name' => 'X'])->assertForbidden();
        actingAs($member)->postJson(categoriesUrl($household, $category, '/archive'))->assertForbidden();
        actingAs($member)->deleteJson(categoriesUrl($household, $category))->assertForbidden();
        expect($category->fresh()->archived_at)->toBeNull();
    });
});

describe('archiving and deleting', function () {
    it('deletes an unused category permanently', function () {
        [$household, $owner] = categoryHousehold();
        $category = Category::factory()->for($household)->create();

        actingAs($owner)->deleteJson(categoriesUrl($household, $category))->assertNoContent();
        expect(Category::count())->toBe(0);
    });

    it('refuses to delete a used category (even by a soft-deleted transaction) and asks to archive', function () {
        [$household, $owner] = categoryHousehold();
        $category = Category::factory()->for($household)->create();
        $wallet = Wallet::factory()->for($household)->create();
        Transaction::factory()->for($household)->for($wallet)->create(['category_id' => $category->id])->delete();

        actingAs($owner)->deleteJson(categoriesUrl($household, $category))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category' => 'Arsipkan']);
        expect($category->fresh())->not->toBeNull();
    });

    it('archives and unarchives idempotently', function () {
        [$household, $owner] = categoryHousehold();
        $category = Category::factory()->for($household)->create();

        $first = actingAs($owner)->postJson(categoriesUrl($household, $category, '/archive'))->assertOk()->json('data.archived_at');
        travel(1)->minutes();
        actingAs($owner)->postJson(categoriesUrl($household, $category, '/archive'))->assertJsonPath('data.archived_at', $first);

        actingAs($owner)->postJson(categoriesUrl($household, $category, '/unarchive'))->assertJsonPath('data.archived_at', null);
    });
});

describe('archived category and transactions (acceptance)', function () {
    /**
     * @return array{0: Household, 1: User, 2: Wallet, 3: Category, 4: Transaction}
     */
    function archivedCategoryWithOldTransaction(): array
    {
        [$household, $owner] = categoryHousehold();
        $wallet = Wallet::factory()->for($household)->create();
        $category = Category::factory()->for($household)->create(['name' => 'Rokok']);

        $tx = actingAs($owner)->postJson("/api/households/{$household->id}/transactions", [
            'wallet_id' => $wallet->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 25_000,
            'occurred_on' => '2026-11-01',
            'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        $category->update(['archived_at' => now()]);

        return [$household, $owner, $wallet, $category, Transaction::findOrFail($tx)];
    }

    it('hides an archived category from new choices', function () {
        [$household, $owner, $wallet, $category] = archivedCategoryWithOldTransaction();

        actingAs($owner)->getJson(categoriesUrl($household))->assertJsonMissing(['id' => $category->id]);

        actingAs($owner)->postJson("/api/households/{$household->id}/transactions", [
            'wallet_id' => $wallet->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 1_000,
            'occurred_on' => '2026-11-02',
            'client_id' => (string) Str::uuid(),
        ])->assertJsonValidationErrors(['category_id' => 'diarsip']);
    });

    it('still shows the archived category on old transactions (list and detail)', function () {
        [$household, $owner, , $category, $tx] = archivedCategoryWithOldTransaction();

        actingAs($owner)->getJson("/api/households/{$household->id}/transactions")
            ->assertOk()
            ->assertJsonPath('data.0.category_id', $category->id)
            ->assertJsonPath('data.0.category.name', 'Rokok')
            ->assertJsonPath('data.0.category.archived_at', $category->archived_at?->toIso8601String());

        actingAs($owner)->getJson("/api/households/{$household->id}/transactions/{$tx->id}")
            ->assertJsonPath('data.category.name', 'Rokok');

        actingAs($owner)->getJson("/api/households/{$household->id}/transactions?category_id={$category->id}")
            ->assertJsonCount(1, 'data');
    });

    it('lets an old transaction be edited while keeping its archived category, but not move to another archived one', function () {
        [$household, $owner, $wallet, $category, $tx] = archivedCategoryWithOldTransaction();
        $otherArchived = Category::factory()->for($household)->archived()->create();
        $url = "/api/households/{$household->id}/transactions/{$tx->id}";

        actingAs($owner)->patchJson($url, ['note' => 'koreksi', 'amount' => 30_000])
            ->assertOk()
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.amount', -30_000);

        actingAs($owner)->patchJson($url, ['category_id' => $otherArchived->id])
            ->assertJsonValidationErrors(['category_id' => 'diarsip']);

        expect($wallet->fresh()->balance)->toBe($wallet->opening_balance - 30_000);
        assertLedgerBalanced();
    });
});
