<?php

use App\Enums\HouseholdRole;
use App\Enums\TransactionType;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;

use function Pest\Laravel\actingAs;

/**
 * @return array{0: Household, 1: User, 2: User, 3: Wallet}
 */
function adjustSetup(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

    return [$household, $owner, $member, Wallet::factory()->for($household)->cash()->opening(500_000)->create()];
}

function adjustUrl(Household $household, Wallet $wallet): string
{
    return "/api/households/{$household->id}/wallets/{$wallet->id}/adjust";
}

it('creates an adjustment for the difference when cash is lower than recorded', function () {
    [$household, $owner, , $wallet] = adjustSetup();

    actingAs($owner)->postJson(adjustUrl($household, $wallet), ['actual_balance' => 437_000, 'occurred_on' => '2026-11-21'])
        ->assertCreated()
        ->assertJsonPath('data.wallet.balance', 437_000)
        ->assertJsonPath('data.adjustment.type', 'adjustment')
        ->assertJsonPath('data.adjustment.amount', -63_000)
        ->assertJsonPath('data.adjustment.category_id', null)
        ->assertJsonPath('data.adjustment.note', 'Penyesuaian saldo');

    expect($wallet->fresh()?->opening_balance)->toBe(500_000); // opening tidak disentuh
    assertLedgerBalanced();
});

it('creates a positive adjustment when the real balance is higher', function () {
    [$household, $owner, , $wallet] = adjustSetup();

    actingAs($owner)->postJson(adjustUrl($household, $wallet), [
        'actual_balance' => 520_000, 'occurred_on' => '2026-11-21', 'note' => 'Uang kembalian ketemu',
    ])->assertCreated()->assertJsonPath('data.adjustment.amount', 20_000);

    assertLedgerBalanced();
});

it('is naturally idempotent: a double submit creates only one adjustment', function () {
    [$household, $owner, , $wallet] = adjustSetup();

    actingAs($owner)->postJson(adjustUrl($household, $wallet), ['actual_balance' => 450_000, 'occurred_on' => '2026-11-21'])->assertCreated();
    actingAs($owner)->postJson(adjustUrl($household, $wallet), ['actual_balance' => 450_000, 'occurred_on' => '2026-11-21'])
        ->assertOk()
        ->assertJsonPath('data.adjustment', null);

    expect(Transaction::where('type', TransactionType::Adjustment)->count())->toBe(1)
        ->and($wallet->fresh()?->balance)->toBe(450_000);
    assertLedgerBalanced();
});

it('accounts for existing transactions before adjusting', function () {
    [$household, $owner, , $wallet] = adjustSetup();
    actingAs($owner)->postJson("/api/households/{$household->id}/transactions", [
        'wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => 100_000, 'occurred_on' => '2026-11-20',
    ])->assertCreated();

    actingAs($owner)->postJson(adjustUrl($household, $wallet), ['actual_balance' => 390_000, 'occurred_on' => '2026-11-21'])
        ->assertJsonPath('data.adjustment.amount', -10_000);

    assertLedgerBalanced();
});

it('can be undone by deleting the adjustment transaction', function () {
    [$household, $owner, , $wallet] = adjustSetup();
    $id = actingAs($owner)->postJson(adjustUrl($household, $wallet), ['actual_balance' => 400_000, 'occurred_on' => '2026-11-21'])
        ->json('data.adjustment.id');

    actingAs($owner)->deleteJson("/api/households/{$household->id}/transactions/{$id}")->assertNoContent();

    expect($wallet->fresh()?->balance)->toBe(500_000);
    assertLedgerBalanced();
});

it('follows the wallet role matrix', function () {
    [$household, $owner, $member, $shared] = adjustSetup();
    $membersPrivate = Wallet::factory()->for($household)->privateTo($member)->opening(100_000)->create();

    actingAs($member)->postJson(adjustUrl($household, $shared), ['actual_balance' => 1, 'occurred_on' => '2026-11-21'])->assertForbidden();
    actingAs($member)->postJson(adjustUrl($household, $membersPrivate), ['actual_balance' => 90_000, 'occurred_on' => '2026-11-21'])->assertCreated();
    actingAs($owner)->postJson(adjustUrl($household, $membersPrivate), ['actual_balance' => 1, 'occurred_on' => '2026-11-21'])->assertForbidden();

    assertLedgerBalanced();
});

it('validates the input', function (array $payload, string $field) {
    [$household, $owner, , $wallet] = adjustSetup();

    actingAs($owner)->postJson(adjustUrl($household, $wallet), array_merge(['actual_balance' => 1, 'occurred_on' => '2026-11-21'], $payload))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing balance' => [['actual_balance' => null], 'actual_balance'],
    'float' => [['actual_balance' => 1000.5], 'actual_balance'],
    'formatted string' => [['actual_balance' => '1.000'], 'actual_balance'],
    'bad date' => [['occurred_on' => 'kemarin'], 'occurred_on'],
]);

it('allows a negative real balance', function () {
    [$household, $owner, , $wallet] = adjustSetup();

    actingAs($owner)->postJson(adjustUrl($household, $wallet), ['actual_balance' => -25_000, 'occurred_on' => '2026-11-21'])
        ->assertCreated()
        ->assertJsonPath('data.adjustment.amount', -525_000);

    assertLedgerBalanced();
});

it('refuses to adjust an archived wallet', function () {
    [$household, $owner, , $wallet] = adjustSetup();
    $wallet->update(['archived_at' => now()]);

    actingAs($owner)->postJson(adjustUrl($household, $wallet), ['actual_balance' => 1, 'occurred_on' => '2026-11-21'])
        ->assertUnprocessable();
});
