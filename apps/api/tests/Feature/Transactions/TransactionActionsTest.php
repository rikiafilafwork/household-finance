<?php

use App\Enums\HouseholdRole;
use App\Models\Category;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;

use function Pest\Laravel\actingAs;

/**
 * @return array{household: Household, owner: User, member: User, wallet: Wallet, cash: Wallet, food: Category, salary: Category}
 */
function ledgerSetup(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

    return [
        'household' => $household,
        'owner' => $owner,
        'member' => $member,
        'wallet' => Wallet::factory()->for($household)->opening(1_000_000)->create(['name' => 'BCA']),
        'cash' => Wallet::factory()->for($household)->cash()->opening(200_000)->create(['name' => 'Dompet']),
        'food' => Category::factory()->for($household)->create(['name' => 'Makan']),
        'salary' => Category::factory()->for($household)->income()->create(['name' => 'Gaji']),
    ];
}

function txUrl(Household $household, ?Transaction $transaction = null): string
{
    return "/api/households/{$household->id}/transactions".($transaction ? "/{$transaction->id}" : '');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function expensePayload(Wallet $wallet, ?Category $category = null, array $overrides = []): array
{
    return array_merge([
        'wallet_id' => $wallet->id,
        'type' => 'expense',
        'amount' => 25_000,
        'occurred_on' => '2026-11-21',
        'category_id' => $category?->id,
        'note' => 'Kopi',
    ], $overrides);
}

describe('create', function () {
    it('stores an expense as a negative amount and lowers the balance', function () {
        $s = ledgerSetup();

        actingAs($s['member'])->postJson(txUrl($s['household']), expensePayload($s['wallet'], $s['food']))
            ->assertCreated()
            ->assertJsonPath('data.type', 'expense')
            ->assertJsonPath('data.amount', -25_000)
            ->assertJsonPath('data.occurred_on', '2026-11-21');

        expect($s['wallet']->fresh()?->balance)->toBe(975_000);
        assertLedgerBalanced();
    });

    it('stores income as a positive amount and raises the balance', function () {
        $s = ledgerSetup();

        actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet'], $s['salary'], [
            'type' => 'income', 'amount' => 8_500_000,
        ]))->assertCreated()->assertJsonPath('data.amount', 8_500_000);

        expect($s['wallet']->fresh()?->balance)->toBe(9_500_000);
        assertLedgerBalanced();
    });

    it('rejects a client-supplied sign, float or string amount', function (mixed $amount) {
        $s = ledgerSetup();

        actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet'], overrides: ['amount' => $amount]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        expect(Transaction::count())->toBe(0);
    })->with([-25_000, 0, 25_000.5, '25.000', '25000']);

    it('validates wallet, category and type against the household', function (string $case, string $field) {
        $s = ledgerSetup();
        $payload = match ($case) {
            'foreign wallet' => expensePayload(Wallet::factory()->create()),
            'archived wallet' => expensePayload(Wallet::factory()->for($s['household'])->archived()->create()),
            'foreign category' => expensePayload($s['wallet'], Category::factory()->create()),
            'kind mismatch' => expensePayload($s['wallet'], $s['salary']),
            'archived category' => expensePayload($s['wallet'], Category::factory()->for($s['household'])->archived()->create()),
            'transfer type' => expensePayload($s['wallet'], overrides: ['type' => 'transfer']),
            'adjustment type' => expensePayload($s['wallet'], overrides: ['type' => 'adjustment']),
            'bad date' => expensePayload($s['wallet'], overrides: ['occurred_on' => '21/11/2026']),
            default => throw new LogicException("Kasus tidak dikenal: {$case}"),
        };

        actingAs($s['owner'])->postJson(txUrl($s['household']), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Transaction::count())->toBe(0);
        assertLedgerBalanced();
    })->with([
        ['foreign wallet', 'wallet_id'],
        ['archived wallet', 'wallet_id'],
        ['foreign category', 'category_id'],
        ['kind mismatch', 'category_id'],
        ['archived category', 'category_id'],
        ['transfer type', 'type'],
        ['adjustment type', 'type'],
        ['bad date', 'occurred_on'],
    ]);

    it('does not let a member write to another member’s private wallet', function () {
        $s = ledgerSetup();
        $ownersPrivate = Wallet::factory()->for($s['household'])->privateTo($s['owner'])->create();

        actingAs($s['member'])->postJson(txUrl($s['household']), expensePayload($ownersPrivate))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wallet_id');
    });

    it('forbids outsiders', function () {
        $s = ledgerSetup();

        actingAs(User::factory()->create())->postJson(txUrl($s['household']), expensePayload($s['wallet']))->assertForbidden();
    });
});

describe('update', function () {
    it('changes the amount by reversing the old effect and applying the new one', function () {
        $s = ledgerSetup();
        $id = actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet'], $s['food']))->json('data.id');

        actingAs($s['owner'])->patchJson(txUrl($s['household'], Transaction::findOrFail($id)), ['amount' => 40_000])
            ->assertOk()
            ->assertJsonPath('data.amount', -40_000);

        expect($s['wallet']->fresh()?->balance)->toBe(960_000);
        assertLedgerBalanced();
    });

    it('moves a transaction to another wallet and updates both balances', function () {
        $s = ledgerSetup();
        $id = actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet'], $s['food']))->json('data.id');

        actingAs($s['owner'])->patchJson(txUrl($s['household'], Transaction::findOrFail($id)), ['wallet_id' => $s['cash']->id])
            ->assertOk()
            ->assertJsonPath('data.wallet_id', $s['cash']->id);

        expect($s['wallet']->fresh()?->balance)->toBe(1_000_000)
            ->and($s['cash']->fresh()?->balance)->toBe(175_000);
        assertLedgerBalanced();
    });

    it('flips the sign when the type changes and drops a mismatched category', function () {
        $s = ledgerSetup();
        $id = actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet'], $s['food']))->json('data.id');

        actingAs($s['owner'])->patchJson(txUrl($s['household'], Transaction::findOrFail($id)), ['type' => 'income'])
            ->assertOk()
            ->assertJsonPath('data.amount', 25_000)
            ->assertJsonPath('data.category_id', null);

        expect($s['wallet']->fresh()?->balance)->toBe(1_025_000);
        assertLedgerBalanced();
    });

    it('rejects moving into an archived wallet and leaves balances untouched', function () {
        $s = ledgerSetup();
        $id = actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet']))->json('data.id');
        $s['cash']->update(['archived_at' => now()]);

        actingAs($s['owner'])->patchJson(txUrl($s['household'], Transaction::findOrFail($id)), ['wallet_id' => $s['cash']->id])
            ->assertUnprocessable();

        expect($s['wallet']->fresh()?->balance)->toBe(975_000)
            ->and($s['cash']->fresh()?->balance)->toBe(200_000);
        assertLedgerBalanced();
    });

    it('lets members edit only their own transactions, the owner edits anyone’s', function () {
        $s = ledgerSetup();
        $ownersTx = actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet']))->json('data.id');
        $membersTx = actingAs($s['member'])->postJson(txUrl($s['household']), expensePayload($s['wallet']))->json('data.id');

        actingAs($s['member'])->patchJson(txUrl($s['household'], Transaction::findOrFail($ownersTx)), ['amount' => 1])->assertForbidden();
        actingAs($s['member'])->patchJson(txUrl($s['household'], Transaction::findOrFail($membersTx)), ['amount' => 30_000])->assertOk();
        actingAs($s['owner'])->patchJson(txUrl($s['household'], Transaction::findOrFail($membersTx)), ['amount' => 35_000])->assertOk();

        assertLedgerBalanced();
    });

    it('does not allow changing status or client_id directly', function (string $field, string $value) {
        $s = ledgerSetup();
        $id = actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet']))->json('data.id');

        actingAs($s['owner'])->patchJson(txUrl($s['household'], Transaction::findOrFail($id)), [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([['status', 'pending'], ['client_id', 'a3bb189e-8bf9-4888-9912-ace4e6543002']]);
});

describe('delete', function () {
    it('soft deletes and reverses the balance effect', function () {
        $s = ledgerSetup();
        $id = actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet']))->json('data.id');

        actingAs($s['owner'])->deleteJson(txUrl($s['household'], Transaction::findOrFail($id)))->assertNoContent();

        expect($s['wallet']->fresh()?->balance)->toBe(1_000_000)
            ->and(Transaction::withTrashed()->findOrFail($id)->trashed())->toBeTrue();
        assertLedgerBalanced();
    });

    it('forbids a member from deleting someone else’s transaction', function () {
        $s = ledgerSetup();
        $id = actingAs($s['owner'])->postJson(txUrl($s['household']), expensePayload($s['wallet']))->json('data.id');

        actingAs($s['member'])->deleteJson(txUrl($s['household'], Transaction::findOrFail($id)))->assertForbidden();

        expect($s['wallet']->fresh()?->balance)->toBe(975_000);
    });
});

describe('pending', function () {
    it('does not move the balance for pending transactions', function () {
        $s = ledgerSetup();
        $pending = Transaction::factory()->forWallet($s['wallet'])->expense(300_000)->pending()->create();

        actingAs($s['owner'])->patchJson(txUrl($s['household'], $pending), ['amount' => 350_000])->assertOk();
        actingAs($s['owner'])->deleteJson(txUrl($s['household'], $pending))->assertNoContent();

        expect($s['wallet']->fresh()?->balance)->toBe(1_000_000);
        assertLedgerBalanced();
    });
});

describe('detail', function () {
    it('shows a transaction only when its wallet is visible', function () {
        $s = ledgerSetup();
        $shared = actingAs($s['member'])->postJson(txUrl($s['household']), expensePayload($s['wallet']))->json('data.id');
        $membersPrivate = Wallet::factory()->for($s['household'])->privateTo($s['member'])->create();
        $private = actingAs($s['member'])->postJson(txUrl($s['household']), expensePayload($membersPrivate))->json('data.id');

        actingAs($s['owner'])->getJson(txUrl($s['household'], Transaction::findOrFail($shared)))->assertOk();
        actingAs($s['owner'])->getJson(txUrl($s['household'], Transaction::findOrFail($private)))->assertForbidden();
        actingAs($s['member'])->getJson(txUrl($s['household'], Transaction::findOrFail($private)))->assertOk();
    });

    it('scopes transactions to their household', function () {
        $s = ledgerSetup();
        $foreign = Transaction::factory()->create();

        actingAs($s['owner'])->getJson(txUrl($s['household'], $foreign))->assertNotFound();
    });
});
