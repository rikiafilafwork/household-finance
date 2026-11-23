<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * @return array{owner: User, bca: Account, cash: Account, food: Category}
 */
function listSetup(): array
{
    $owner = User::factory()->create();

    return [
        'owner' => $owner,
        'bca' => Account::factory()->forUser($owner)->create(),
        'cash' => Account::factory()->forUser($owner)->cash()->create(),
        'food' => Category::factory()->forUser($owner)->create(),
    ];
}

/**
 * @param  array<string, scalar>  $query
 */
function listUrl(array $query = []): string
{
    return '/api/transactions'.($query ? '?'.http_build_query($query) : '');
}

it('lists newest first by date then id', function () {
    $s = listSetup();
    $old = Transaction::factory()->forAccount($s['bca'])->create(['occurred_on' => '2026-11-01']);
    $newA = Transaction::factory()->forAccount($s['bca'])->create(['occurred_on' => '2026-11-20']);
    $newB = Transaction::factory()->forAccount($s['cash'])->create(['occurred_on' => '2026-11-20']);

    actingAs($s['owner'])->getJson(listUrl())
        ->assertOk()
        ->assertJsonPath('data.*.id', [$newB->id, $newA->id, $old->id]);
});

it('filters by account, category, type, date range and note', function (string $filter) {
    $s = listSetup();
    $match = Transaction::factory()->forAccount($s['bca'])->create([
        'category_id' => $s['food']->id, 'occurred_on' => '2026-11-15', 'note' => 'Bakso 100% enak',
    ]);
    Transaction::factory()->forAccount($s['cash'])->income()->create(['occurred_on' => '2026-10-01', 'note' => 'Gaji']);

    $query = match ($filter) {
        'account' => ['account_id' => $s['bca']->id],
        'category' => ['category_id' => $s['food']->id],
        'type' => ['type' => 'expense'],
        'date range' => ['from' => '2026-11-01', 'to' => '2026-11-30'],
        'note search' => ['q' => 'bakso'],
        'literal percent' => ['q' => '100%'],
        default => throw new LogicException($filter),
    };

    actingAs($s['owner'])->getJson(listUrl($query))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$match->id]);
})->with(['account', 'category', 'type', 'date range', 'note search', 'literal percent']);

it('treats % and _ in the search term literally', function () {
    $s = listSetup();
    Transaction::factory()->forAccount($s['bca'])->create(['note' => 'Diskon 50']);

    actingAs($s['owner'])->getJson(listUrl(['q' => '%']))->assertJsonCount(0, 'data');
    actingAs($s['owner'])->getJson(listUrl(['q' => '_']))->assertJsonCount(0, 'data');
});

it('excludes soft-deleted transactions', function () {
    $s = listSetup();
    Transaction::factory()->forAccount($s['bca'])->create()->delete();
    $kept = Transaction::factory()->forAccount($s['bca'])->create();

    actingAs($s['owner'])->getJson(listUrl())->assertJsonPath('data.*.id', [$kept->id]);
});

it('paginates with a stable cursor even when new rows arrive between pages', function () {
    $s = listSetup();
    $all = Transaction::factory()->forAccount($s['bca'])->count(25)->create(['occurred_on' => '2026-11-20']);
    $expected = $all->sortByDesc('id')->pluck('id')->values()->all();

    $seen = [];
    $url = listUrl(['per_page' => 10]);
    $pages = 0;
    while ($url !== null) {
        $response = actingAs($s['owner'])->getJson($url)->assertOk();
        $seen = [...$seen, ...$response->json('data.*.id')];
        $url = $response->json('links.next');
        $pages++;

        // Transaksi baru masuk di tengah paging: tidak boleh menggeser halaman berikutnya.
        if ($pages === 1) {
            Transaction::factory()->forAccount($s['bca'])->create(['occurred_on' => '2026-11-21']);
        }
    }

    expect($pages)->toBe(3)
        ->and($seen)->toBe($expected); // tanpa dobel, tanpa yang terlewat
});

it('validates filters', function (array $query, string $field) {
    $s = listSetup();

    actingAs($s['owner'])->getJson(listUrl($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'bad type' => [['type' => 'gift'], 'type'],
    'bad date' => [['from' => '1 Nov'], 'from'],
    'to before from' => [['from' => '2026-11-10', 'to' => '2026-11-01'], 'to'],
    'per_page too large' => [['per_page' => 500], 'per_page'],
]);

describe('isolation', function () {
    it('never shows another user’s transactions, even when filtering by their account or searching their note', function () {
        $s = listSetup();
        $mine = Transaction::factory()->forAccount($s['bca'])->create();
        $foreignAccount = Account::factory()->create();
        Transaction::factory()->count(3)->forAccount($foreignAccount)->create(['note' => 'rahasia']);

        actingAs($s['owner'])->getJson(listUrl())->assertJsonPath('data.*.id', [$mine->id]);
        actingAs($s['owner'])->getJson(listUrl(['q' => 'rahasia']))->assertJsonCount(0, 'data');
        actingAs($s['owner'])->getJson(listUrl(['account_id' => $foreignAccount->id]))->assertJsonCount(0, 'data');
    });

    it('requires login', function () {
        getJson(listUrl())->assertUnauthorized();
    });
});
