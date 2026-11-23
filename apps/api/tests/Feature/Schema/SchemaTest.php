<?php

use App\Enums\CategoryKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DefaultCategories;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\seed;

// ---------- Constraint DB (Keputusan #5, § Schema) ----------

it('defaults the user timezone to Asia/Jakarta', function () {
    expect(User::factory()->create()->refresh()->timezone)->toBe('Asia/Jakarta');
});

it('rejects duplicate account names per user', function () {
    $user = User::factory()->create();
    Account::factory()->forUser($user)->create(['name' => 'BCA']);

    Account::factory()->forUser($user)->create(['name' => 'BCA']);
})->throws(QueryException::class, 'uq_account_name');

it('allows the same account name for different users', function () {
    Account::factory()->create(['name' => 'BCA']);
    Account::factory()->create(['name' => 'BCA']);

    assertDatabaseCount('accounts', 2);
});

it('rejects duplicate category names per user and kind', function () {
    $user = User::factory()->create();
    Category::factory()->forUser($user)->create(['name' => 'Makan']);

    Category::factory()->forUser($user)->create(['name' => 'Makan']);
})->throws(QueryException::class, 'uq_category_name');

it('allows the same category name for different kinds', function () {
    $user = User::factory()->create();
    Category::factory()->forUser($user)->create(['name' => 'Lainnya']);
    Category::factory()->forUser($user)->income()->create(['name' => 'Lainnya']);

    expect($user->categories()->count())->toBe(2);
});

it('rejects a duplicate client_id for the same user', function () {
    $account = Account::factory()->create();
    $clientId = (string) Str::uuid();
    Transaction::factory()->forAccount($account)->create(['client_id' => $clientId]);

    Transaction::factory()->forAccount($account)->create(['client_id' => $clientId]);
})->throws(QueryException::class, 'uq_tx_client');

it('allows the same client_id for different users and repeated NULLs', function () {
    $clientId = (string) Str::uuid();
    Transaction::factory()->forAccount(Account::factory()->create())->create(['client_id' => $clientId]);
    Transaction::factory()->forAccount(Account::factory()->create())->create(['client_id' => $clientId]);
    Transaction::factory()->count(2)->forAccount(Account::factory()->create())->create(['client_id' => null]);

    assertDatabaseCount('transactions', 4);
});

it('allows at most one transaction per plan item (soft-deleted rows included)', function () {
    $account = Account::factory()->create();
    $item = PlanItem::factory()->forUser($account->user)->create();
    $first = Transaction::factory()->forAccount($account)->create(['plan_item_id' => $item->id]);
    $first->delete();

    Transaction::factory()->forAccount($account)->create(['plan_item_id' => $item->id]);
})->throws(QueryException::class, 'uq_tx_plan_item');

it('cascades accounts and categories when a user is deleted', function () {
    $account = Account::factory()->create();
    DefaultCategories::seedFor($account->user);

    $account->user->delete();

    assertDatabaseCount('accounts', 0);
    assertDatabaseCount('categories', 0);
});

it('refuses at the database level to delete an account that still has transactions', function () {
    $account = Account::factory()->create();
    Transaction::factory()->forAccount($account)->create();

    $account->delete(); // FK transactions.account_id tanpa cascade: riwayat tidak boleh hilang diam-diam
})->throws(QueryException::class, 'transactions_account_id_foreign');

it('seeds default categories idempotently', function () {
    $user = User::factory()->create();

    DefaultCategories::seedFor($user);
    DefaultCategories::seedFor($user);

    expect($user->categories()->count())->toBe(count(DefaultCategories::all()))
        ->and($user->categories()->where('kind', CategoryKind::Income)->pluck('name')->all())->toContain('Gaji');
});

it('runs the development seeder (demo user with accounts, no household)', function () {
    seed(DatabaseSeeder::class);

    $user = User::where('email', 'demo@example.com')->firstOrFail();
    expect($user->accounts()->orderBy('sort_order')->pluck('name')->all())->toBe(['BCA', 'Bank Jago', 'GoPay', 'Dompet'])
        ->and($user->accounts()->sum('balance'))->toEqual(7_050_000)
        ->and($user->categories()->count())->toBe(count(DefaultCategories::all()));
});

// ---------- Trait BelongsToUser ----------

it('returns nothing from scoped models without a logged-in user', function () {
    $account = Account::factory()->create();
    Transaction::factory()->forAccount($account)->create();
    Category::factory()->forUser($account->user)->create();

    expect(Account::count())->toBe(0)
        ->and(Account::find($account->id))->toBeNull()
        ->and(Transaction::count())->toBe(0)
        ->and(Category::count())->toBe(0);
});

it('scopes queries to the logged-in user only', function () {
    $mine = Account::factory()->create();
    $theirs = Account::factory()->create();
    Transaction::factory()->forAccount($mine)->create();
    Transaction::factory()->count(3)->forAccount($theirs)->create();

    actingAs($mine->user);

    expect(Account::pluck('id')->all())->toBe([$mine->id])
        ->and(Account::find($theirs->id))->toBeNull()
        ->and(Transaction::count())->toBe(1)
        ->and(Account::ownedBy($theirs->user)->pluck('id')->all())->toBe([$theirs->id]);
});

it('fills user_id from the logged-in user on create', function () {
    $user = User::factory()->create();
    actingAs($user);

    $category = Category::create(['kind' => CategoryKind::Expense, 'name' => 'Kopi']);

    expect($category->user_id)->toBe($user->id);
});

it('keeps an explicit user_id on create', function () {
    $owner = User::factory()->create();
    actingAs(User::factory()->create());

    $category = Category::create(['user_id' => $owner->id, 'kind' => CategoryKind::Expense, 'name' => 'Kopi']);

    expect($category->user_id)->toBe($owner->id);
});
