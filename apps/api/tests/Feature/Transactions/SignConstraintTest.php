<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
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
    /** @var Wallet $wallet */
    $wallet = Wallet::factory()->create();

    DB::table('transactions')->insert(array_merge([
        'household_id' => $wallet->household_id,
        'wallet_id' => $wallet->id,
        'category_id' => null,
        'type' => 'expense',
        'status' => 'posted',
        'amount' => -25_000,
        'occurred_on' => '2026-11-21',
        'created_by' => User::factory()->create()->id,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('accepts correctly signed rows', function (array $row) {
    insertRawTransaction($row);

    expect(Transaction::count())->toBe(1);
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

it('requires an owner for private wallets', function () {
    Wallet::factory()->create(['visibility' => 'private', 'owner_user_id' => null]);
})->throws(QueryException::class, 'chk_private_has_owner');

it('enforces client_id uniqueness per household but allows many NULLs', function () {
    $wallet = Wallet::factory()->create();
    Transaction::factory()->forWallet($wallet)->count(3)->create(['client_id' => null]);
    Transaction::factory()->forWallet($wallet)->create(['client_id' => 'a3bb189e-8bf9-4888-9912-ace4e6543002']);

    // client_id yang sama di household LAIN boleh.
    Transaction::factory()->create(['client_id' => 'a3bb189e-8bf9-4888-9912-ace4e6543002']);
    expect(Transaction::count())->toBe(5);

    Transaction::factory()->forWallet($wallet)->create(['client_id' => 'a3bb189e-8bf9-4888-9912-ace4e6543002']);
})->throws(QueryException::class, 'uq_tx_client');

it('enforces import_hash uniqueness per wallet', function () {
    $wallet = Wallet::factory()->create();
    $hash = hash('sha256', 'baris-mutasi');
    Transaction::factory()->forWallet($wallet)->create(['import_hash' => $hash]);
    Transaction::factory()->create(['import_hash' => $hash]); // wallet lain boleh

    Transaction::factory()->forWallet($wallet)->create(['import_hash' => $hash]);
})->throws(QueryException::class, 'uq_tx_import');

it('stores money as signed 64-bit integers', function () {
    $wallet = Wallet::factory()->opening(9_000_000_000_000_000)->create();
    insertRawTransaction(['type' => 'income', 'amount' => 9_000_000_000_000_000]);

    expect($wallet->fresh()?->balance)->toBe(9_000_000_000_000_000)
        ->and(Transaction::sole()->amount)->toBe(9_000_000_000_000_000);
});

it('soft deletes transactions', function () {
    $transaction = Transaction::factory()->create();

    $transaction->delete();

    expect(Transaction::count())->toBe(0)
        ->and(Transaction::withTrashed()->count())->toBe(1);
});
