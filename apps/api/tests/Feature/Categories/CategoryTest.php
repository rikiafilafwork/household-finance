<?php

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\travel;

function categoriesUrl(?Category $category = null, string $suffix = ''): string
{
    return '/api/categories'.($category ? "/{$category->id}" : '').$suffix;
}

describe('creating', function () {
    it('creates a category with color and icon', function () {
        $user = User::factory()->create();

        actingAs($user)->postJson(categoriesUrl(), [
            'kind' => 'expense',
            'name' => '  Jajan  ',
            'color' => '#F97316',
            'icon' => 'ice-cream-cone',
        ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'expense')
            ->assertJsonPath('data.name', 'Jajan')
            ->assertJsonPath('data.color', '#F97316')
            ->assertJsonPath('data.icon', 'ice-cream-cone')
            ->assertJsonPath('data.archived_at', null);

        expect(Category::sole()->user_id)->toBe($user->id);
    });

    it('requires login', function () {
        getJson(categoriesUrl())->assertUnauthorized();
    });

    it('rejects a duplicate name within the same kind but allows it across kinds and users', function () {
        $user = User::factory()->create();
        Category::factory()->forUser($user)->create(['kind' => CategoryKind::Expense, 'name' => 'Lainnya']);
        Category::factory()->create(['kind' => CategoryKind::Expense, 'name' => 'Punya orang']);

        actingAs($user)->postJson(categoriesUrl(), ['kind' => 'expense', 'name' => 'Lainnya'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'sudah ada']);

        actingAs($user)->postJson(categoriesUrl(), ['kind' => 'income', 'name' => 'Lainnya'])->assertCreated();
        actingAs($user)->postJson(categoriesUrl(), ['kind' => 'expense', 'name' => 'Punya orang'])->assertCreated();
    });

    it('validates kind, color, and icon', function (array $payload, string $field) {
        actingAs(User::factory()->create())->postJson(categoriesUrl(), [...['kind' => 'expense', 'name' => 'Ok'], ...$payload])
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
        $user = User::factory()->create();
        Category::factory()->forUser($user)->create(['name' => 'Makan']);
        Category::factory()->forUser($user)->archived()->create(['name' => 'Rokok']);
        Category::factory()->forUser($user)->income()->create(['name' => 'Gaji']);
        Category::factory()->create(['name' => 'Milik user lain']);

        actingAs($user)->getJson(categoriesUrl())
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.*.name', ['Gaji', 'Makan']); // income dulu (urutan enum), lalu nama

        actingAs($user)->getJson(categoriesUrl().'?include_archived=1&kind=expense')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Makan', 'Rokok']);
    });

    it('returns 404 for another user’s category on every action', function () {
        $foreign = Category::factory()->create(['name' => 'Asli']);
        $user = User::factory()->create();

        actingAs($user)->getJson(categoriesUrl($foreign))->assertNotFound();
        actingAs($user)->patchJson(categoriesUrl($foreign), ['name' => 'X'])->assertNotFound();
        actingAs($user)->postJson(categoriesUrl($foreign, '/archive'))->assertNotFound();
        actingAs($user)->deleteJson(categoriesUrl($foreign))->assertNotFound();

        $fresh = Category::ownedBy($foreign->user_id)->findOrFail($foreign->id);
        expect($fresh->name)->toBe('Asli')->and($fresh->archived_at)->toBeNull();
    });
});

describe('updating', function () {
    it('renames and recolors', function () {
        $category = Category::factory()->create(['name' => 'Makan']);

        actingAs($category->user)->patchJson(categoriesUrl($category), ['name' => 'Makan & Minum', 'color' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Makan & Minum')
            ->assertJsonPath('data.color', null);
    });

    it('allows keeping its own name but rejects another category name', function () {
        $category = Category::factory()->create(['name' => 'Makan']);
        Category::factory()->forUser($category->user)->create(['name' => 'Transport']);

        actingAs($category->user)->patchJson(categoriesUrl($category), ['name' => 'Makan'])->assertOk();
        actingAs($category->user)->patchJson(categoriesUrl($category), ['name' => 'Transport'])
            ->assertJsonValidationErrors('name');
    });

    it('refuses to change kind', function () {
        $category = Category::factory()->create();

        actingAs($category->user)->patchJson(categoriesUrl($category), ['kind' => 'income'])
            ->assertJsonValidationErrors('kind');
        expect($category->fresh()?->kind)->toBe(CategoryKind::Expense);
    });
});

describe('archiving and deleting', function () {
    it('deletes an unused category permanently', function () {
        $category = Category::factory()->create();

        actingAs($category->user)->deleteJson(categoriesUrl($category))->assertNoContent();
        expect(Category::count())->toBe(0);
    });

    it('refuses to delete a used category (even by a soft-deleted transaction) and asks to archive', function () {
        $category = Category::factory()->create();
        $account = Account::factory()->forUser($category->user)->create();
        Transaction::factory()->forAccount($account)->create(['category_id' => $category->id])->delete();

        actingAs($category->user)->deleteJson(categoriesUrl($category))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category' => 'Arsipkan']);
        expect($category->fresh())->not->toBeNull();
    });

    it('archives and unarchives idempotently', function () {
        $category = Category::factory()->create();

        $first = actingAs($category->user)->postJson(categoriesUrl($category, '/archive'))->assertOk()->json('data.archived_at');
        travel(1)->minutes();
        actingAs($category->user)->postJson(categoriesUrl($category, '/archive'))->assertJsonPath('data.archived_at', $first);

        actingAs($category->user)->postJson(categoriesUrl($category, '/unarchive'))->assertJsonPath('data.archived_at', null);
    });
});

describe('archived category and transactions (acceptance)', function () {
    /**
     * @return array{0: User, 1: Account, 2: Category, 3: Transaction}
     */
    function archivedCategoryWithOldTransaction(): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->create();
        $category = Category::factory()->forUser($user)->create(['name' => 'Rokok']);

        $tx = actingAs($user)->postJson('/api/transactions', [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 25_000,
            'occurred_on' => '2026-11-01',
            'client_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        $category->update(['archived_at' => now()]);

        return [$user, $account, $category, Transaction::findOrFail($tx)];
    }

    it('hides an archived category from new choices', function () {
        [$user, $account, $category] = archivedCategoryWithOldTransaction();

        actingAs($user)->getJson(categoriesUrl())->assertJsonMissing(['id' => $category->id]);

        actingAs($user)->postJson('/api/transactions', [
            'account_id' => $account->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 1_000,
            'occurred_on' => '2026-11-02',
            'client_id' => (string) Str::uuid(),
        ])->assertJsonValidationErrors(['category_id' => 'diarsip']);
    });

    it('still shows the archived category on old transactions (list and detail)', function () {
        [$user, , $category, $tx] = archivedCategoryWithOldTransaction();

        actingAs($user)->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.category_id', $category->id)
            ->assertJsonPath('data.0.category.name', 'Rokok')
            ->assertJsonPath('data.0.category.archived_at', $category->archived_at?->toIso8601String());

        actingAs($user)->getJson("/api/transactions/{$tx->id}")
            ->assertJsonPath('data.category.name', 'Rokok');

        actingAs($user)->getJson("/api/transactions?category_id={$category->id}")
            ->assertJsonCount(1, 'data');
    });

    it('lets an old transaction be edited while keeping its archived category, but not move to another archived one', function () {
        [$user, $account, $category, $tx] = archivedCategoryWithOldTransaction();
        $otherArchived = Category::factory()->forUser($user)->archived()->create();
        $url = "/api/transactions/{$tx->id}";

        actingAs($user)->patchJson($url, ['note' => 'koreksi', 'amount' => 30_000])
            ->assertOk()
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.amount', -30_000);

        actingAs($user)->patchJson($url, ['category_id' => $otherArchived->id])
            ->assertJsonValidationErrors(['category_id' => 'diarsip']);

        expect($account->fresh()?->balance)->toBe($account->opening_balance - 30_000);
        assertLedgerBalanced();
    });
});
