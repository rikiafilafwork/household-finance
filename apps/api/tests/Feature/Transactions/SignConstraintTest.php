<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Keputusan #2: tanda amount ditegakkan DATABASE, bukan hanya kode PHP.
| Insert langsung lewat query builder (melewati model & Actions) untuk membuktikannya.
*/

/**
 * @param  array<string, mixed>  $overrides
 */
function insertRawTransaction(array $overrides = []): void
{
    /** @var Account $account */
    $account = Account::factory()->create();

    DB::table('transactions')->insert(array_merge([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'category_id' => null,
        'type' => 'expense',
        'amount' => -25_000,
        'occurred_on' => '2026-11-21',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('accepts correctly signed rows', function (array $row) {
    insertRawTransaction($row);

    expect(DB::table('transactions')->count())->toBe(1);
})->with([
    'income positive' => [['type' => 'income', 'amount' => 5_000_000]],
    'expense negative' => [['type' => 'expense', 'amount' => -25_000]],
    'transfer out' => [['type' => 'transfer', 'amount' => -100_000, 'transfer_group_id' => (string) Str::uuid()]],
    'transfer in' => [['type' => 'transfer', 'amount' => 100_000, 'transfer_group_id' => (string) Str::uuid()]],
    'adjustment up' => [['type' => 'adjustment', 'amount' => 1]],
    'adjustment down' => [['type' => 'adjustment', 'amount' => -1]],
]);

it('rejects wrongly signed rows at the database level', function (array $row) {
    insertRawTransaction($row);
})->throws(QueryException::class, 'chk_tx_sign')->with([
    'expense positive' => [['type' => 'expense', 'amount' => 25_000]],
    'expense zero' => [['type' => 'expense', 'amount' => 0]],
    'income negative' => [['type' => 'income', 'amount' => -5_000_000]],
    'income zero' => [['type' => 'income', 'amount' => 0]],
    'transfer without group' => [['type' => 'transfer', 'amount' => -100_000]],
    'transfer zero' => [['type' => 'transfer', 'amount' => 0, 'transfer_group_id' => (string) Str::uuid()]],
    'adjustment zero' => [['type' => 'adjustment', 'amount' => 0]],
]);

it('rejects a category on transfers and adjustments', function (string $type, int $amount) {
    $category = Category::factory()->create();

    insertRawTransaction([
        'type' => $type,
        'amount' => $amount,
        'category_id' => $category->id,
        'transfer_group_id' => (string) Str::uuid(),
    ]);
})->throws(QueryException::class, 'chk_tx_category')->with([
    ['transfer', -100_000],
    ['adjustment', 50_000],
]);

it('enforces import_hash uniqueness per account', function () {
    $account = Account::factory()->create();
    $hash = hash('sha256', 'baris-mutasi');
    Transaction::factory()->forAccount($account)->create(['import_hash' => $hash]);
    Transaction::factory()->create(['import_hash' => $hash]); // akun lain boleh

    Transaction::factory()->forAccount($account)->create(['import_hash' => $hash]);
})->throws(QueryException::class, 'uq_tx_import');

it('stores money as signed 64-bit integers', function () {
    $account = Account::factory()->opening(9_000_000_000_000_000)->create();
    insertRawTransaction(['type' => 'income', 'amount' => 9_000_000_000_000_000]);

    expect($account->fresh()?->balance)->toBe(9_000_000_000_000_000)
        ->and(Transaction::query()->withoutGlobalScopes()->sole()->amount)->toBe(9_000_000_000_000_000);
});

it('soft deletes transactions', function () {
    $transaction = Transaction::factory()->create();

    $transaction->delete();

    expect(Transaction::ownedBy($transaction->user_id)->count())->toBe(0)
        ->and(Transaction::ownedBy($transaction->user_id)->withTrashed()->count())->toBe(1);
});
