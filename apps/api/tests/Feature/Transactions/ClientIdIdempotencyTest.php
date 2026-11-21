<?php

use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
| Keputusan #11: retry dengan client_id yang sama → transaksi yang sudah ada (200), tanpa efek apa pun.
| Versi paralel (proses terpisah, data ter-commit) ada di tests/Concurrency.
*/

/**
 * @return array{0: Household, 1: User, 2: Wallet, 3: Wallet}
 */
function idempotencySetup(): array
{
    $owner = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();

    return [
        $household,
        $owner,
        Wallet::factory()->for($household)->opening(1_000_000)->create(),
        Wallet::factory()->for($household)->opening(0)->create(),
    ];
}

it('returns the existing transaction with 200 on retry and changes the balance once', function () {
    [$household, $owner, $wallet] = idempotencySetup();
    $payload = [
        'wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => 25_000,
        'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
    ];

    $first = actingAs($owner)->postJson("/api/households/{$household->id}/transactions", $payload)->assertCreated();
    $second = actingAs($owner)->postJson("/api/households/{$household->id}/transactions", $payload)->assertOk();
    $third = actingAs($owner)->postJson("/api/households/{$household->id}/transactions", $payload)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($third->json('data.id'))->toBe($first->json('data.id'))
        ->and(Transaction::count())->toBe(1)
        ->and($wallet->fresh()?->balance)->toBe(975_000);
    assertLedgerBalanced();
});

it('returns the existing transfer with 200 on retry', function () {
    [$household, $owner, $from, $to] = idempotencySetup();
    $payload = [
        'from_wallet_id' => $from->id, 'to_wallet_id' => $to->id, 'amount' => 100_000,
        'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
    ];

    $first = actingAs($owner)->postJson("/api/households/{$household->id}/transfers", $payload)->assertCreated();
    actingAs($owner)->postJson("/api/households/{$household->id}/transfers", $payload)
        ->assertOk()
        ->assertJsonPath('data.transfer_group_id', $first->json('data.transfer_group_id'));

    expect(Transaction::count())->toBe(2)
        ->and($from->fresh()?->balance)->toBe(900_000)
        ->and($to->fresh()?->balance)->toBe(100_000);
    assertLedgerBalanced();
});

it('puts client_id only on the outgoing leg of a transfer', function () {
    [$household, $owner, $from, $to] = idempotencySetup();
    $clientId = (string) Str::uuid();

    actingAs($owner)->postJson("/api/households/{$household->id}/transfers", [
        'from_wallet_id' => $from->id, 'to_wallet_id' => $to->id, 'amount' => 1,
        'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertCreated();

    expect(Transaction::where('amount', '<', 0)->sole()->client_id)->toBe($clientId)
        ->and(Transaction::where('amount', '>', 0)->sole()->client_id)->toBeNull();
});

it('requires a client_id on every create', function (string $endpoint, array $payload) {
    [$household, $owner, $from, $to] = idempotencySetup();
    $payload = array_map(fn ($v) => match ($v) {
        'FROM' => $from->id, 'TO' => $to->id, default => $v
    }, $payload);

    actingAs($owner)->postJson("/api/households/{$household->id}/{$endpoint}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_id');

    expect(Transaction::count())->toBe(0);
})->with([
    'transaction without client_id' => ['transactions', ['wallet_id' => 'FROM', 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21']],
    'transaction with invalid client_id' => ['transactions', ['wallet_id' => 'FROM', 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => 'bukan-uuid']],
    'transfer without client_id' => ['transfers', ['from_wallet_id' => 'FROM', 'to_wallet_id' => 'TO', 'amount' => 1, 'occurred_on' => '2026-11-21']],
]);

it('treats client_id case-insensitively', function () {
    [$household, $owner, $wallet] = idempotencySetup();
    $clientId = (string) Str::uuid();
    $payload = ['wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21'];

    actingAs($owner)->postJson("/api/households/{$household->id}/transactions", $payload + ['client_id' => $clientId])->assertCreated();
    actingAs($owner)->postJson("/api/households/{$household->id}/transactions", $payload + ['client_id' => strtoupper($clientId)])->assertOk();

    expect(Transaction::count())->toBe(1);
});

it('scopes client_id per household', function () {
    [$householdA, $ownerA, $walletA] = idempotencySetup();
    [$householdB, $ownerB, $walletB] = idempotencySetup();
    $clientId = (string) Str::uuid();

    actingAs($ownerA)->postJson("/api/households/{$householdA->id}/transactions", [
        'wallet_id' => $walletA->id, 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertCreated();
    actingAs($ownerB)->postJson("/api/households/{$householdB->id}/transactions", [
        'wallet_id' => $walletB->id, 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertCreated();

    expect(Transaction::count())->toBe(2);
});

it('refuses to reuse a client_id across transactions and transfers', function () {
    [$household, $owner, $from, $to] = idempotencySetup();
    $clientId = (string) Str::uuid();

    actingAs($owner)->postJson("/api/households/{$household->id}/transfers", [
        'from_wallet_id' => $from->id, 'to_wallet_id' => $to->id, 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertCreated();

    actingAs($owner)->postJson("/api/households/{$household->id}/transactions", [
        'wallet_id' => $from->id, 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertUnprocessable()->assertJsonValidationErrors('client_id');

    expect(Transaction::count())->toBe(2);
    assertLedgerBalanced();
});

it('documents the known limitation: a retry with different content returns the original (ADR-010)', function () {
    [$household, $owner, $wallet] = idempotencySetup();
    $clientId = (string) Str::uuid();
    $base = ['wallet_id' => $wallet->id, 'type' => 'expense', 'occurred_on' => '2026-11-21', 'client_id' => $clientId];

    actingAs($owner)->postJson("/api/households/{$household->id}/transactions", $base + ['amount' => 25_000])->assertCreated();
    actingAs($owner)->postJson("/api/households/{$household->id}/transactions", $base + ['amount' => 99_000])
        ->assertOk()
        ->assertJsonPath('data.amount', -25_000);

    expect($wallet->fresh()?->balance)->toBe(975_000);
});
