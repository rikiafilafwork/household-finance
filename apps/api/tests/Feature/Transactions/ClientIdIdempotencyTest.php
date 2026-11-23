<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
| Keputusan #15: retry dengan client_id yang sama → transaksi yang sudah ada (200), tanpa efek apa pun.
| Versi paralel (proses terpisah, data ter-commit) ada di tests/Concurrency.
*/

/**
 * @return array{0: User, 1: Account, 2: Account}
 */
function idempotencySetup(): array
{
    $owner = User::factory()->create();

    return [
        $owner,
        Account::factory()->forUser($owner)->opening(1_000_000)->create(),
        Account::factory()->forUser($owner)->opening(0)->create(),
    ];
}

it('returns the existing transaction with 200 on retry and changes the balance once', function () {
    [$owner, $account] = idempotencySetup();
    $payload = [
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 25_000,
        'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
    ];

    $first = actingAs($owner)->postJson('/api/transactions', $payload)->assertCreated();
    $second = actingAs($owner)->postJson('/api/transactions', $payload)->assertOk();
    $third = actingAs($owner)->postJson('/api/transactions', $payload)->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($third->json('data.id'))->toBe($first->json('data.id'))
        ->and(Transaction::count())->toBe(1)
        ->and($account->fresh()?->balance)->toBe(975_000);
    assertLedgerBalanced();
});

it('returns the existing transfer with 200 on retry', function () {
    [$owner, $from, $to] = idempotencySetup();
    $payload = [
        'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 100_000,
        'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
    ];

    $first = actingAs($owner)->postJson('/api/transfers', $payload)->assertCreated();
    actingAs($owner)->postJson('/api/transfers', $payload)
        ->assertOk()
        ->assertJsonPath('data.transfer_group_id', $first->json('data.transfer_group_id'));

    expect(Transaction::count())->toBe(2)
        ->and($from->fresh()?->balance)->toBe(900_000)
        ->and($to->fresh()?->balance)->toBe(100_000);
    assertLedgerBalanced();
});

it('puts client_id only on the outgoing leg of a transfer', function () {
    [$owner, $from, $to] = idempotencySetup();
    $clientId = (string) Str::uuid();

    actingAs($owner)->postJson('/api/transfers', [
        'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 1,
        'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertCreated();

    expect(Transaction::where('amount', '<', 0)->sole()->client_id)->toBe($clientId)
        ->and(Transaction::where('amount', '>', 0)->sole()->client_id)->toBeNull();
});

it('requires a client_id on every create', function (string $endpoint, array $payload) {
    [$owner, $from, $to] = idempotencySetup();
    $payload = array_map(fn ($v) => match ($v) {
        'FROM' => $from->id, 'TO' => $to->id, default => $v
    }, $payload);

    actingAs($owner)->postJson("/api/{$endpoint}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('client_id');

    expect(Transaction::count())->toBe(0);
})->with([
    'transaction without client_id' => ['transactions', ['account_id' => 'FROM', 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21']],
    'transaction with invalid client_id' => ['transactions', ['account_id' => 'FROM', 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => 'bukan-uuid']],
    'transfer without client_id' => ['transfers', ['from_account_id' => 'FROM', 'to_account_id' => 'TO', 'amount' => 1, 'occurred_on' => '2026-11-21']],
]);

it('treats client_id case-insensitively', function () {
    [$owner, $account] = idempotencySetup();
    $clientId = (string) Str::uuid();
    $payload = ['account_id' => $account->id, 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21'];

    actingAs($owner)->postJson('/api/transactions', $payload + ['client_id' => $clientId])->assertCreated();
    actingAs($owner)->postJson('/api/transactions', $payload + ['client_id' => strtoupper($clientId)])->assertOk();

    expect(Transaction::count())->toBe(1);
});

it('scopes client_id per user: another user’s client_id is neither replayed nor blocked', function () {
    [$ownerA, $accountA] = idempotencySetup();
    [$ownerB, $accountB] = idempotencySetup();
    $clientId = (string) Str::uuid();

    actingAs($ownerA)->postJson('/api/transactions', [
        'account_id' => $accountA->id, 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertCreated();
    // B memakai client_id yang sama: transaksi baru milik B, bukan replay transaksi A.
    actingAs($ownerB)->postJson('/api/transactions', [
        'account_id' => $accountB->id, 'type' => 'expense', 'amount' => 2, 'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertCreated()->assertJsonPath('data.amount', -2)->assertJsonPath('data.account_id', $accountB->id);

    expect(Transaction::ownedBy($ownerA)->count())->toBe(1)
        ->and(Transaction::ownedBy($ownerB)->count())->toBe(1);
});

it('refuses to reuse a client_id across transactions and transfers', function () {
    [$owner, $from, $to] = idempotencySetup();
    $clientId = (string) Str::uuid();

    actingAs($owner)->postJson('/api/transfers', [
        'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertCreated();

    actingAs($owner)->postJson('/api/transactions', [
        'account_id' => $from->id, 'type' => 'expense', 'amount' => 1, 'occurred_on' => '2026-11-21', 'client_id' => $clientId,
    ])->assertUnprocessable()->assertJsonValidationErrors('client_id');

    expect(Transaction::count())->toBe(2);
    assertLedgerBalanced();
});

it('documents the known limitation: a retry with different content returns the original (ADR-010)', function () {
    [$owner, $account] = idempotencySetup();
    $clientId = (string) Str::uuid();
    $base = ['account_id' => $account->id, 'type' => 'expense', 'occurred_on' => '2026-11-21', 'client_id' => $clientId];

    actingAs($owner)->postJson('/api/transactions', $base + ['amount' => 25_000])->assertCreated();
    actingAs($owner)->postJson('/api/transactions', $base + ['amount' => 99_000])
        ->assertOk()
        ->assertJsonPath('data.amount', -25_000);

    expect($account->fresh()?->balance)->toBe(975_000);
});
