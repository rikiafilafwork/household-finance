<?php

use App\Enums\HouseholdRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;

use function Pest\Laravel\actingAs;

/**
 * @return array{household: Household, owner: User, member: User, bca: Wallet, cash: Wallet, food: Category}
 */
function listSetup(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

    return [
        'household' => $household,
        'owner' => $owner,
        'member' => $member,
        'bca' => Wallet::factory()->for($household)->create(),
        'cash' => Wallet::factory()->for($household)->cash()->create(),
        'food' => Category::factory()->for($household)->create(),
    ];
}

/**
 * @param  array<string, scalar>  $query
 */
function listUrl(Household $household, array $query = []): string
{
    return "/api/households/{$household->id}/transactions".($query ? '?'.http_build_query($query) : '');
}

it('lists newest first by date then id', function () {
    $s = listSetup();
    $old = Transaction::factory()->forWallet($s['bca'])->create(['occurred_on' => '2026-11-01']);
    $newA = Transaction::factory()->forWallet($s['bca'])->create(['occurred_on' => '2026-11-20']);
    $newB = Transaction::factory()->forWallet($s['cash'])->create(['occurred_on' => '2026-11-20']);

    actingAs($s['owner'])->getJson(listUrl($s['household']))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$newB->id, $newA->id, $old->id]);
});

it('filters by wallet, category, type, status, date range and note', function (string $filter) {
    $s = listSetup();
    $match = Transaction::factory()->forWallet($s['bca'])->create([
        'category_id' => $s['food']->id, 'occurred_on' => '2026-11-15', 'note' => 'Bakso 100% enak',
    ]);
    Transaction::factory()->forWallet($s['cash'])->income()->pending()->create(['occurred_on' => '2026-10-01', 'note' => 'Gaji']);

    $query = match ($filter) {
        'wallet' => ['wallet_id' => $s['bca']->id],
        'category' => ['category_id' => $s['food']->id],
        'type' => ['type' => 'expense'],
        'status' => ['status' => 'posted'],
        'date range' => ['from' => '2026-11-01', 'to' => '2026-11-30'],
        'note search' => ['q' => 'bakso'],
        'literal percent' => ['q' => '100%'],
        default => throw new LogicException($filter),
    };

    actingAs($s['owner'])->getJson(listUrl($s['household'], $query))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$match->id]);
})->with(['wallet', 'category', 'type', 'status', 'date range', 'note search', 'literal percent']);

it('treats % and _ in the search term literally', function () {
    $s = listSetup();
    Transaction::factory()->forWallet($s['bca'])->create(['note' => 'Diskon 50']);

    actingAs($s['owner'])->getJson(listUrl($s['household'], ['q' => '%']))->assertJsonCount(0, 'data');
    actingAs($s['owner'])->getJson(listUrl($s['household'], ['q' => '_']))->assertJsonCount(0, 'data');
});

it('excludes soft-deleted transactions', function () {
    $s = listSetup();
    Transaction::factory()->forWallet($s['bca'])->create()->delete();
    $kept = Transaction::factory()->forWallet($s['bca'])->create();

    actingAs($s['owner'])->getJson(listUrl($s['household']))->assertJsonPath('data.*.id', [$kept->id]);
});

it('paginates with a stable cursor even when new rows arrive between pages', function () {
    $s = listSetup();
    $all = Transaction::factory()->forWallet($s['bca'])->count(25)->create(['occurred_on' => '2026-11-20']);
    $expected = $all->sortByDesc('id')->pluck('id')->values()->all();

    $seen = [];
    $url = listUrl($s['household'], ['per_page' => 10]);
    $pages = 0;
    while ($url !== null) {
        $response = actingAs($s['owner'])->getJson($url)->assertOk();
        $seen = [...$seen, ...$response->json('data.*.id')];
        $url = $response->json('links.next');
        $pages++;

        // Transaksi baru masuk di tengah paging: tidak boleh menggeser halaman berikutnya.
        if ($pages === 1) {
            Transaction::factory()->forWallet($s['bca'])->create(['occurred_on' => '2026-11-21']);
        }
    }

    expect($pages)->toBe(3)
        ->and($seen)->toBe($expected); // tanpa dobel, tanpa yang terlewat
});

it('validates filters', function (array $query, string $field) {
    $s = listSetup();

    actingAs($s['owner'])->getJson(listUrl($s['household'], $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'bad type' => [['type' => 'gift'], 'type'],
    'bad date' => [['from' => '1 Nov'], 'from'],
    'to before from' => [['from' => '2026-11-10', 'to' => '2026-11-01'], 'to'],
    'per_page too large' => [['per_page' => 500], 'per_page'],
]);

describe('privacy', function () {
    it('never shows transactions from another member’s private wallet, even to the household owner', function () {
        $s = listSetup();
        $membersPrivate = Wallet::factory()->for($s['household'])->privateTo($s['member'])->create();
        $shared = Transaction::factory()->forWallet($s['bca'])->create();
        $secret = Transaction::factory()->forWallet($membersPrivate)->create(['note' => 'rahasia']);

        actingAs($s['owner'])->getJson(listUrl($s['household']))->assertJsonPath('data.*.id', [$shared->id]);
        actingAs($s['owner'])->getJson(listUrl($s['household'], ['q' => 'rahasia']))->assertJsonCount(0, 'data');
        actingAs($s['owner'])->getJson(listUrl($s['household'], ['wallet_id' => $membersPrivate->id]))->assertJsonCount(0, 'data');

        actingAs($s['member'])->getJson(listUrl($s['household']))
            ->assertJsonPath('data.*.id', [$secret->id, $shared->id]);
    });

    it('never leaks transactions from other households', function () {
        $s = listSetup();
        Transaction::factory()->count(3)->create();

        actingAs($s['owner'])->getJson(listUrl($s['household']))->assertJsonCount(0, 'data');
    });

    it('forbids outsiders', function () {
        $s = listSetup();

        actingAs(User::factory()->create())->getJson(listUrl($s['household']))->assertForbidden();
    });
});
