<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

function accountsUrl(?Account $account = null, string $suffix = ''): string
{
    return '/api/accounts'.($account ? "/{$account->id}" : '').$suffix;
}

describe('creating', function () {
    it('creates an account with an opening balance', function () {
        $user = User::factory()->create();

        actingAs($user)->postJson(accountsUrl(), [
            'name' => 'BCA',
            'type' => 'bank',
            'opening_balance' => 12_500_000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'bank')
            ->assertJsonPath('data.opening_balance', 12_500_000)
            ->assertJsonPath('data.balance', 12_500_000);

        expect(Account::sole()->user_id)->toBe($user->id);
    });

    it('puts new accounts last unless sort_order is given', function () {
        $user = User::factory()->create();

        actingAs($user)->postJson(accountsUrl(), ['name' => 'BCA', 'type' => 'bank'])->assertJsonPath('data.sort_order', 1);
        actingAs($user)->postJson(accountsUrl(), ['name' => 'GoPay', 'type' => 'ewallet'])->assertJsonPath('data.sort_order', 2);
        actingAs($user)->postJson(accountsUrl(), ['name' => 'Dompet', 'type' => 'cash', 'sort_order' => 0])->assertJsonPath('data.sort_order', 0);

        actingAs($user)->getJson(accountsUrl())->assertJsonPath('data.*.name', ['Dompet', 'BCA', 'GoPay']);
    });

    it('rejects a duplicate name for the same user but not for another user', function () {
        $user = User::factory()->create();
        Account::factory()->forUser($user)->create(['name' => 'BCA']);
        Account::factory()->create(['name' => 'Jago']); // user lain

        actingAs($user)->postJson(accountsUrl(), ['name' => ' BCA ', 'type' => 'bank'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Rekening dengan nama ini sudah ada.']);
        actingAs($user)->postJson(accountsUrl(), ['name' => 'Jago', 'type' => 'bank'])->assertCreated();
    });

    it('validates input and only accepts integer money', function (array $payload, string $field) {
        actingAs(User::factory()->create())->postJson(accountsUrl(), array_merge(['name' => 'X', 'type' => 'bank'], $payload))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'missing name' => [['name' => ''], 'name'],
        'unknown type' => [['type' => 'crypto'], 'type'],
        'float balance' => [['opening_balance' => 1250.5], 'opening_balance'],
        'formatted string balance' => [['opening_balance' => '1.250.000'], 'opening_balance'],
        'numeric string balance' => [['opening_balance' => '1250000'], 'opening_balance'],
        'too large' => [['opening_balance' => 2_000_000_000_000_000], 'opening_balance'],
    ]);

    it('requires login', function () {
        postJson(accountsUrl(), ['name' => 'X', 'type' => 'bank'])->assertUnauthorized();
    });
});

describe('listing', function () {
    it('lists only the user’s own accounts, ordered by sort_order then name', function () {
        $user = User::factory()->create();
        $b = Account::factory()->forUser($user)->create(['name' => 'B', 'sort_order' => 1]);
        $a = Account::factory()->forUser($user)->create(['name' => 'A', 'sort_order' => 1]);
        $first = Account::factory()->forUser($user)->cash()->create(['name' => 'Z', 'sort_order' => 0]);
        Account::factory()->create(); // user lain

        actingAs($user)->getJson(accountsUrl())
            ->assertOk()
            ->assertJsonPath('data.*.id', [$first->id, $a->id, $b->id]);
    });

    it('hides archived accounts unless requested', function () {
        $user = User::factory()->create();
        Account::factory()->forUser($user)->create();
        Account::factory()->forUser($user)->archived()->create();

        actingAs($user)->getJson(accountsUrl())->assertJsonCount(1, 'data');
        actingAs($user)->getJson(accountsUrl().'?include_archived=1')->assertJsonCount(2, 'data');
    });

    it('returns 404 for another user’s account', function () {
        $foreign = Account::factory()->create();

        actingAs(User::factory()->create())->getJson(accountsUrl($foreign))->assertNotFound();
    });
});

describe('updating', function () {
    it('renames an account and shifts the balance when the opening balance is corrected', function () {
        $account = Account::factory()->opening(1_000_000)->create();
        // Simulasikan sudah ada pengeluaran 250 rb (balance biasanya dijaga Actions).
        Transaction::factory()->forAccount($account)->expense(250_000)->create();
        $account->update(['balance' => 750_000]);

        actingAs($account->user)->patchJson(accountsUrl($account), ['name' => 'BCA Utama', 'opening_balance' => 1_200_000, 'sort_order' => 5])
            ->assertOk()
            ->assertJsonPath('data.name', 'BCA Utama')
            ->assertJsonPath('data.opening_balance', 1_200_000)
            ->assertJsonPath('data.balance', 950_000)
            ->assertJsonPath('data.sort_order', 5);

        assertLedgerBalanced();
    });

    it('does not allow changing the balance directly', function () {
        $account = Account::factory()->create();

        actingAs($account->user)->patchJson(accountsUrl($account), ['balance' => 999_999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('balance');
    });

    it('allows keeping the same name but not taking another account’s name', function () {
        $account = Account::factory()->create(['name' => 'BCA']);
        Account::factory()->forUser($account->user)->create(['name' => 'Jago']);

        actingAs($account->user)->patchJson(accountsUrl($account), ['name' => 'BCA'])->assertOk();
        actingAs($account->user)->patchJson(accountsUrl($account), ['name' => 'Jago'])->assertJsonValidationErrors('name');
    });
});

describe('archiving and deleting', function () {
    it('archives and unarchives an account', function () {
        $account = Account::factory()->create();

        actingAs($account->user)->postJson(accountsUrl($account, '/archive'))
            ->assertOk()
            ->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        actingAs($account->user)->postJson(accountsUrl($account, '/unarchive'))
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);
    });

    it('deletes an account without transactions', function () {
        $account = Account::factory()->create();

        actingAs($account->user)->deleteJson(accountsUrl($account))->assertNoContent();

        expect(Account::ownedBy($account->user)->count())->toBe(0);
    });

    it('refuses to delete an account with transactions, even soft-deleted ones', function () {
        $account = Account::factory()->create();
        Transaction::factory()->forAccount($account)->create()->delete();

        actingAs($account->user)->deleteJson(accountsUrl($account))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        expect(Account::ownedBy($account->user)->count())->toBe(1);
    });
});
