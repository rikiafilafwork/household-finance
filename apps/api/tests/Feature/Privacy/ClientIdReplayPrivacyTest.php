<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
 * Replay idempoten (Keputusan #11) mengembalikan baris lama. Itu tidak boleh jadi jalan pintas
 * melewati VisibleWallets: client_id milik transaksi di wallet privat orang lain → 422 tanpa isi.
 */

/**
 * @return array{0: Household, 1: User, 2: User, 3: Wallet, 4: Wallet}
 */
function replaySetup(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);
    $shared = Wallet::factory()->for($household)->opening(1_000_000)->create();
    $private = Wallet::factory()->for($household)->privateTo($member)->opening(5_000_000)->create(['name' => 'Rahasia']);

    return [$household, $owner, $member, $shared, $private];
}

it('does not replay a transaction from someone else\'s private wallet', function (string $who) {
    [$household, $owner, $member, $shared, $private] = replaySetup();
    $clientId = (string) Str::uuid();
    $url = "/api/households/{$household->id}/transactions";

    actingAs($member)->postJson($url, [
        'wallet_id' => $private->id, 'type' => 'expense', 'amount' => 777_000,
        'occurred_on' => '2026-11-21', 'note' => 'hadiah kejutan', 'client_id' => $clientId,
    ])->assertCreated();

    $intruder = $who === 'owner household' ? $owner : User::factory()->create();
    if ($who === 'member lain') {
        $household->members()->attach($intruder, ['role' => HouseholdRole::Member, 'joined_at' => now()]);
    }

    actingAs($intruder)->postJson($url, [
        'wallet_id' => $shared->id, 'type' => 'expense', 'amount' => 1_000,
        'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_id')
        ->assertJsonMissingPath('data')
        ->assertDontSee('hadiah kejutan')
        ->assertDontSee('777000');

    expect(Transaction::count())->toBe(1)
        ->and($shared->fresh()?->balance)->toBe(1_000_000);
    assertLedgerBalanced();
})->with(['owner household', 'member lain']);

it('does not replay a transfer that touches someone else\'s private wallet', function () {
    [$household, $owner, $member, $shared, $private] = replaySetup();
    $clientId = (string) Str::uuid();
    $url = "/api/households/{$household->id}/transfers";
    $ownerCash = Wallet::factory()->for($household)->cash()->create();

    actingAs($member)->postJson($url, [
        'from_wallet_id' => $shared->id, 'to_wallet_id' => $private->id, 'amount' => 300_000,
        'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertCreated();

    // Baris keluar ada di wallet shared (terlihat owner), tapi pasangannya di wallet privat member.
    actingAs($owner)->postJson($url, [
        'from_wallet_id' => $shared->id, 'to_wallet_id' => $ownerCash->id, 'amount' => 1_000,
        'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_id')
        ->assertJsonMissingPath('data');

    expect(Transaction::count())->toBe(2);
    assertLedgerBalanced();
});

it('still replays for the original sender (and for others when everything is visible)', function () {
    [$household, $owner, $member, $shared, $private] = replaySetup();
    $url = "/api/households/{$household->id}/transactions";
    $privateId = (string) Str::uuid();
    $sharedId = (string) Str::uuid();
    $payload = ['type' => 'expense', 'amount' => 5_000, 'occurred_on' => '2026-11-21'];

    $first = actingAs($member)->postJson($url, [...$payload, 'wallet_id' => $private->id, 'client_id' => $privateId])->assertCreated()->json('data.id');
    actingAs($member)->postJson($url, [...$payload, 'wallet_id' => $private->id, 'client_id' => $privateId])
        ->assertOk()->assertJsonPath('data.id', $first);

    $sharedTx = actingAs($member)->postJson($url, [...$payload, 'wallet_id' => $shared->id, 'client_id' => $sharedId])->assertCreated()->json('data.id');
    actingAs($owner)->postJson($url, [...$payload, 'wallet_id' => $shared->id, 'client_id' => $sharedId])
        ->assertOk()->assertJsonPath('data.id', $sharedTx);
});
