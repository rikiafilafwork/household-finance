<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

function adjustAccount(): Account
{
    return Account::factory()->cash()->opening(500_000)->create();
}

function adjustUrl(Account $account): string
{
    return "/api/accounts/{$account->id}/adjust";
}

it('creates an adjustment for the difference when cash is lower than recorded', function () {
    $account = adjustAccount();

    actingAs($account->user)->postJson(adjustUrl($account), ['actual_balance' => 437_000, 'occurred_on' => '2026-11-21'])
        ->assertCreated()
        ->assertJsonPath('data.account.balance', 437_000)
        ->assertJsonPath('data.adjustment.type', 'adjustment')
        ->assertJsonPath('data.adjustment.amount', -63_000)
        ->assertJsonPath('data.adjustment.category_id', null)
        ->assertJsonPath('data.adjustment.note', 'Penyesuaian saldo');

    expect($account->fresh()?->opening_balance)->toBe(500_000); // opening tidak disentuh
    assertLedgerBalanced();
});

it('creates a positive adjustment when the real balance is higher', function () {
    $account = adjustAccount();

    actingAs($account->user)->postJson(adjustUrl($account), [
        'actual_balance' => 520_000, 'occurred_on' => '2026-11-21', 'note' => 'Uang kembalian ketemu',
    ])->assertCreated()->assertJsonPath('data.adjustment.amount', 20_000);

    assertLedgerBalanced();
});

it('is naturally idempotent: a double submit creates only one adjustment', function () {
    $account = adjustAccount();

    actingAs($account->user)->postJson(adjustUrl($account), ['actual_balance' => 450_000, 'occurred_on' => '2026-11-21'])->assertCreated();
    actingAs($account->user)->postJson(adjustUrl($account), ['actual_balance' => 450_000, 'occurred_on' => '2026-11-21'])
        ->assertOk()
        ->assertJsonPath('data.adjustment', null);

    expect(Transaction::where('type', TransactionType::Adjustment)->count())->toBe(1)
        ->and($account->fresh()?->balance)->toBe(450_000);
    assertLedgerBalanced();
});

it('accounts for existing transactions before adjusting', function () {
    $account = adjustAccount();
    actingAs($account->user)->postJson('/api/transactions', [
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 100_000, 'occurred_on' => '2026-11-20',
        'client_id' => (string) Str::uuid(),
    ])->assertCreated();

    actingAs($account->user)->postJson(adjustUrl($account), ['actual_balance' => 390_000, 'occurred_on' => '2026-11-21'])
        ->assertJsonPath('data.adjustment.amount', -10_000);

    assertLedgerBalanced();
});

it('can be undone by deleting the adjustment transaction', function () {
    $account = adjustAccount();
    $id = actingAs($account->user)->postJson(adjustUrl($account), ['actual_balance' => 400_000, 'occurred_on' => '2026-11-21'])
        ->json('data.adjustment.id');

    actingAs($account->user)->deleteJson("/api/transactions/{$id}")->assertNoContent();

    expect($account->fresh()?->balance)->toBe(500_000);
    assertLedgerBalanced();
});

it('returns 404 for another user’s account and changes nothing', function () {
    $account = adjustAccount();
    $intruder = Account::factory()->create()->user;

    actingAs($intruder)->postJson(adjustUrl($account), ['actual_balance' => 1, 'occurred_on' => '2026-11-21'])->assertNotFound();

    expect($account->fresh()?->balance)->toBe(500_000)
        ->and(Transaction::ownedBy($account->user)->count())->toBe(0);
});

it('validates the input', function (array $payload, string $field) {
    $account = adjustAccount();

    actingAs($account->user)->postJson(adjustUrl($account), array_merge(['actual_balance' => 1, 'occurred_on' => '2026-11-21'], $payload))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing balance' => [['actual_balance' => null], 'actual_balance'],
    'float' => [['actual_balance' => 1000.5], 'actual_balance'],
    'formatted string' => [['actual_balance' => '1.000'], 'actual_balance'],
    'bad date' => [['occurred_on' => 'kemarin'], 'occurred_on'],
]);

it('allows a negative real balance', function () {
    $account = adjustAccount();

    actingAs($account->user)->postJson(adjustUrl($account), ['actual_balance' => -25_000, 'occurred_on' => '2026-11-21'])
        ->assertCreated()
        ->assertJsonPath('data.adjustment.amount', -525_000);

    assertLedgerBalanced();
});

it('refuses to adjust an archived account', function () {
    $account = adjustAccount();
    $account->update(['archived_at' => now()]);

    actingAs($account->user)->postJson(adjustUrl($account), ['actual_balance' => 1, 'occurred_on' => '2026-11-21'])
        ->assertUnprocessable();
});
