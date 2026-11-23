<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * @return array{user: User, account: Account, cash: Account, food: Category, salary: Category}
 */
function ledgerSetup(): array
{
    $user = User::factory()->create();

    return [
        'user' => $user,
        'account' => Account::factory()->forUser($user)->opening(1_000_000)->create(['name' => 'BCA']),
        'cash' => Account::factory()->forUser($user)->cash()->opening(200_000)->create(['name' => 'Dompet']),
        'food' => Category::factory()->forUser($user)->create(['name' => 'Makan']),
        'salary' => Category::factory()->forUser($user)->income()->create(['name' => 'Gaji']),
    ];
}

function txUrl(Transaction|int|null $transaction = null): string
{
    $id = $transaction instanceof Transaction ? $transaction->id : $transaction;

    return '/api/transactions'.($id ? "/{$id}" : '');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function expensePayload(Account $account, ?Category $category = null, array $overrides = []): array
{
    return array_merge([
        'account_id' => $account->id,
        'type' => 'expense',
        'amount' => 25_000,
        'occurred_on' => '2026-11-21',
        'category_id' => $category?->id,
        'note' => 'Kopi',
        'client_id' => (string) Str::uuid(),
    ], $overrides);
}

describe('create', function () {
    it('stores an expense as a negative amount and lowers the balance', function () {
        $s = ledgerSetup();

        actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account'], $s['food']))
            ->assertCreated()
            ->assertJsonPath('data.type', 'expense')
            ->assertJsonPath('data.amount', -25_000)
            ->assertJsonPath('data.account_id', $s['account']->id)
            ->assertJsonPath('data.plan_item_id', null)
            ->assertJsonPath('data.occurred_on', '2026-11-21');

        expect($s['account']->fresh()?->balance)->toBe(975_000)
            ->and(Transaction::sole()->user_id)->toBe($s['user']->id);
        assertLedgerBalanced();
    });

    it('stores income as a positive amount and raises the balance', function () {
        $s = ledgerSetup();

        actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account'], $s['salary'], [
            'type' => 'income', 'amount' => 8_500_000,
        ]))->assertCreated()->assertJsonPath('data.amount', 8_500_000);

        expect($s['account']->fresh()?->balance)->toBe(9_500_000);
        assertLedgerBalanced();
    });

    it('allows the balance to go negative (cash often goes unrecorded)', function () {
        $s = ledgerSetup();

        actingAs($s['user'])->postJson(txUrl(), expensePayload($s['cash'], overrides: ['amount' => 250_000]))->assertCreated();

        expect($s['cash']->fresh()?->balance)->toBe(-50_000);
        assertLedgerBalanced();
    });

    it('rejects a client-supplied sign, float or string amount', function (mixed $amount) {
        $s = ledgerSetup();

        actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account'], overrides: ['amount' => $amount]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        expect(Transaction::count())->toBe(0);
    })->with([-25_000, 0, 25_000.5, '25.000', '25000']);

    it('validates account, category and type', function (string $case, string $field) {
        $s = ledgerSetup();
        $payload = match ($case) {
            'archived account' => expensePayload(Account::factory()->forUser($s['user'])->archived()->create()),
            'foreign category' => expensePayload($s['account'], Category::factory()->create()),
            'kind mismatch' => expensePayload($s['account'], $s['salary']),
            'archived category' => expensePayload($s['account'], Category::factory()->forUser($s['user'])->archived()->create()),
            'transfer type' => expensePayload($s['account'], overrides: ['type' => 'transfer']),
            'adjustment type' => expensePayload($s['account'], overrides: ['type' => 'adjustment']),
            'bad date' => expensePayload($s['account'], overrides: ['occurred_on' => '21/11/2026']),
            default => throw new LogicException("Kasus tidak dikenal: {$case}"),
        };

        actingAs($s['user'])->postJson(txUrl(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Transaction::count())->toBe(0);
        assertLedgerBalanced();
    })->with([
        ['archived account', 'account_id'],
        ['foreign category', 'category_id'],
        ['kind mismatch', 'category_id'],
        ['archived category', 'category_id'],
        ['transfer type', 'type'],
        ['adjustment type', 'type'],
        ['bad date', 'occurred_on'],
    ]);

    it('returns 404 when writing to another user’s account', function () {
        $s = ledgerSetup();
        $foreign = Account::factory()->opening(500_000)->create();

        actingAs($s['user'])->postJson(txUrl(), expensePayload($foreign))->assertNotFound();

        expect($foreign->fresh()?->balance)->toBe(500_000)
            ->and(Transaction::ownedBy($foreign->user)->count())->toBe(0);
    });

    it('requires login', function () {
        $s = ledgerSetup();

        postJson(txUrl(), expensePayload($s['account']))->assertUnauthorized();
    });
});

describe('update', function () {
    it('changes the amount by reversing the old effect and applying the new one', function () {
        $s = ledgerSetup();
        $id = actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account'], $s['food']))->json('data.id');

        actingAs($s['user'])->patchJson(txUrl($id), ['amount' => 40_000])
            ->assertOk()
            ->assertJsonPath('data.amount', -40_000);

        expect($s['account']->fresh()?->balance)->toBe(960_000);
        assertLedgerBalanced();
    });

    it('moves a transaction to another account and updates both balances', function () {
        $s = ledgerSetup();
        $id = actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account'], $s['food']))->json('data.id');

        actingAs($s['user'])->patchJson(txUrl($id), ['account_id' => $s['cash']->id])
            ->assertOk()
            ->assertJsonPath('data.account_id', $s['cash']->id);

        expect($s['account']->fresh()?->balance)->toBe(1_000_000)
            ->and($s['cash']->fresh()?->balance)->toBe(175_000);
        assertLedgerBalanced();
    });

    it('flips the sign when the type changes and drops a mismatched category', function () {
        $s = ledgerSetup();
        $id = actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account'], $s['food']))->json('data.id');

        actingAs($s['user'])->patchJson(txUrl($id), ['type' => 'income'])
            ->assertOk()
            ->assertJsonPath('data.amount', 25_000)
            ->assertJsonPath('data.category_id', null);

        expect($s['account']->fresh()?->balance)->toBe(1_025_000);
        assertLedgerBalanced();
    });

    it('rejects moving into an archived account and leaves balances untouched', function () {
        $s = ledgerSetup();
        $id = actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account']))->json('data.id');
        $s['cash']->update(['archived_at' => now()]);

        actingAs($s['user'])->patchJson(txUrl($id), ['account_id' => $s['cash']->id])
            ->assertUnprocessable();

        expect($s['account']->fresh()?->balance)->toBe(975_000)
            ->and($s['cash']->fresh()?->balance)->toBe(200_000);
        assertLedgerBalanced();
    });

    it('returns 404 when moving into another user’s account', function () {
        $s = ledgerSetup();
        $id = actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account']))->json('data.id');
        $foreign = Account::factory()->opening(500_000)->create();

        actingAs($s['user'])->patchJson(txUrl($id), ['account_id' => $foreign->id])->assertNotFound();

        expect($foreign->fresh()?->balance)->toBe(500_000)
            ->and($s['account']->fresh()?->balance)->toBe(975_000);
        assertLedgerBalanced();
    });

    it('does not allow changing client_id directly', function () {
        $s = ledgerSetup();
        $id = actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account']))->json('data.id');

        actingAs($s['user'])->patchJson(txUrl($id), ['client_id' => 'a3bb189e-8bf9-4888-9912-ace4e6543002'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_id');
    });
});

describe('delete', function () {
    it('soft deletes and reverses the balance effect', function () {
        $s = ledgerSetup();
        $id = actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account']))->json('data.id');

        actingAs($s['user'])->deleteJson(txUrl($id))->assertNoContent();

        expect($s['account']->fresh()?->balance)->toBe(1_000_000)
            ->and(Transaction::withTrashed()->findOrFail($id)->trashed())->toBeTrue();
        assertLedgerBalanced();
    });

    it('releases plan_item_id so the checklist item can be checked again (Keputusan #8)', function () {
        $s = ledgerSetup();
        $item = PlanItem::factory()->forUser($s['user'])->create(['category_id' => $s['food']->id]);
        $linked = Transaction::factory()->forAccount($s['account'])->expense(100_000)->create(['plan_item_id' => $item->id]);
        $s['account']->update(['balance' => 900_000]);

        actingAs($s['user'])->deleteJson(txUrl($linked))->assertNoContent();

        expect(Transaction::withTrashed()->findOrFail($linked->id)->plan_item_id)->toBeNull();
        // Item yang sama bisa terhubung ke transaksi baru tanpa melanggar uq_tx_plan_item.
        Transaction::factory()->forAccount($s['account'])->expense(100_000)->create(['plan_item_id' => $item->id]);
        expect(Transaction::where('plan_item_id', $item->id)->count())->toBe(1);
    });
});

describe('detail', function () {
    it('shows the user’s own transaction', function () {
        $s = ledgerSetup();
        $id = actingAs($s['user'])->postJson(txUrl(), expensePayload($s['account'], $s['food']))->json('data.id');

        actingAs($s['user'])->getJson(txUrl($id))
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Makan');
    });

    it('returns 404 for another user’s transaction on read, update and delete', function () {
        $s = ledgerSetup();
        $foreign = Transaction::factory()->create();

        actingAs($s['user'])->getJson(txUrl($foreign))->assertNotFound();
        actingAs($s['user'])->patchJson(txUrl($foreign), ['amount' => 1])->assertNotFound();
        actingAs($s['user'])->deleteJson(txUrl($foreign))->assertNotFound();

        expect(Transaction::ownedBy($foreign->user_id)->whereKey($foreign->id)->exists())->toBeTrue();
    });
});
