<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;

use function Pest\Laravel\actingAs;

/**
 * @return array{0: Household, 1: User, 2: User}
 */
function walletHousehold(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

    return [$household, $owner, $member];
}

function walletsUrl(Household $household, ?Wallet $wallet = null, string $suffix = ''): string
{
    return "/api/households/{$household->id}/wallets".($wallet ? "/{$wallet->id}" : '').$suffix;
}

describe('creating', function () {
    it('lets the owner create a shared wallet with an opening balance', function () {
        [$household, $owner] = walletHousehold();

        actingAs($owner)->postJson(walletsUrl($household), [
            'name' => 'BCA Bersama',
            'type' => 'bank',
            'opening_balance' => 12_500_000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.visibility', 'shared')
            ->assertJsonPath('data.opening_balance', 12_500_000)
            ->assertJsonPath('data.balance', 12_500_000);

        expect(Wallet::sole()->owner_user_id)->toBeNull();
    });

    it('forbids members from creating shared wallets', function () {
        [$household, , $member] = walletHousehold();

        actingAs($member)->postJson(walletsUrl($household), ['name' => 'X', 'type' => 'cash'])->assertForbidden();
    });

    it('lets any member create a private wallet owned by themselves', function () {
        [$household, , $member] = walletHousehold();

        actingAs($member)->postJson(walletsUrl($household), ['name' => 'Dompet Pribadi', 'type' => 'cash', 'visibility' => 'private'])
            ->assertCreated()
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.is_mine', true);

        expect(Wallet::sole()->owner_user_id)->toBe($member->id);
    });

    it('validates input and only accepts integer money', function (array $payload, string $field) {
        [$household, $owner] = walletHousehold();

        actingAs($owner)->postJson(walletsUrl($household), array_merge(['name' => 'X', 'type' => 'bank'], $payload))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'missing name' => [['name' => ''], 'name'],
        'unknown type' => [['type' => 'crypto'], 'type'],
        'unknown visibility' => [['visibility' => 'secret'], 'visibility'],
        'float balance' => [['opening_balance' => 1250.5], 'opening_balance'],
        'formatted string balance' => [['opening_balance' => '1.250.000'], 'opening_balance'],
        'numeric string balance' => [['opening_balance' => '1250000'], 'opening_balance'],
        'too large' => [['opening_balance' => 2_000_000_000_000_000], 'opening_balance'],
    ]);

    it('forbids outsiders', function () {
        [$household] = walletHousehold();

        actingAs(User::factory()->create())->postJson(walletsUrl($household), ['name' => 'X', 'type' => 'bank', 'visibility' => 'private'])
            ->assertForbidden();
    });
});

describe('visibility', function () {
    it('shows shared wallets and only your own private wallets, even to the household owner', function () {
        [$household, $owner, $member] = walletHousehold();
        $shared = Wallet::factory()->for($household)->create(['name' => 'A Shared']);
        $ownersPrivate = Wallet::factory()->for($household)->privateTo($owner)->create(['name' => 'B Owner Private']);
        $membersPrivate = Wallet::factory()->for($household)->privateTo($member)->create(['name' => 'C Member Private']);
        Wallet::factory()->create(); // household lain

        actingAs($owner)->getJson(walletsUrl($household))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$shared->id, $ownersPrivate->id]);

        actingAs($member)->getJson(walletsUrl($household))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$shared->id, $membersPrivate->id]);
    });

    it('returns 403 for another member’s private wallet detail', function () {
        [$household, $owner, $member] = walletHousehold();
        $membersPrivate = Wallet::factory()->for($household)->privateTo($member)->create();

        actingAs($owner)->getJson(walletsUrl($household, $membersPrivate))->assertForbidden();
        actingAs($member)->getJson(walletsUrl($household, $membersPrivate))->assertOk();
    });

    it('hides archived wallets unless requested', function () {
        [$household, $owner] = walletHousehold();
        Wallet::factory()->for($household)->create();
        Wallet::factory()->for($household)->archived()->create();

        actingAs($owner)->getJson(walletsUrl($household))->assertJsonCount(1, 'data');
        actingAs($owner)->getJson(walletsUrl($household).'?include_archived=1')->assertJsonCount(2, 'data');
    });

    it('scopes wallets to their household', function () {
        [$household, $owner] = walletHousehold();
        $foreign = Wallet::factory()->create();

        actingAs($owner)->getJson(walletsUrl($household, $foreign))->assertNotFound();
    });
});

describe('updating', function () {
    it('renames a wallet and shifts the balance when the opening balance is corrected', function () {
        [$household, $owner] = walletHousehold();
        $wallet = Wallet::factory()->for($household)->opening(1_000_000)->create();
        // Simulasikan sudah ada pengeluaran 250 rb (balance dijaga Actions di #16).
        Transaction::factory()->forWallet($wallet)->expense(250_000)->create();
        $wallet->update(['balance' => 750_000]);

        actingAs($owner)->patchJson(walletsUrl($household, $wallet), ['name' => 'BCA Utama', 'opening_balance' => 1_200_000])
            ->assertOk()
            ->assertJsonPath('data.name', 'BCA Utama')
            ->assertJsonPath('data.opening_balance', 1_200_000)
            ->assertJsonPath('data.balance', 950_000);

        // Invariant: balance = opening_balance + SUM(amount)
        $fresh = $wallet->fresh();
        expect($fresh?->balance)->toBe($fresh?->opening_balance + (int) $wallet->transactions()->sum('amount'));
    });

    it('does not allow changing visibility or balance directly', function (string $field, mixed $value) {
        [$household, $owner] = walletHousehold();
        $wallet = Wallet::factory()->for($household)->create();

        actingAs($owner)->patchJson(walletsUrl($household, $wallet), [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        ['visibility', 'private'],
        ['balance', 999_999],
    ]);

    it('applies the role matrix to updates', function () {
        [$household, $owner, $member] = walletHousehold();
        $shared = Wallet::factory()->for($household)->create();
        $membersPrivate = Wallet::factory()->for($household)->privateTo($member)->create();

        actingAs($member)->patchJson(walletsUrl($household, $shared), ['name' => 'X'])->assertForbidden();
        actingAs($member)->patchJson(walletsUrl($household, $membersPrivate), ['name' => 'Milikku'])->assertOk();
        actingAs($owner)->patchJson(walletsUrl($household, $membersPrivate), ['name' => 'X'])->assertForbidden();
    });
});

describe('archiving and deleting', function () {
    it('lets the owner archive and unarchive a shared wallet', function () {
        [$household, $owner, $member] = walletHousehold();
        $wallet = Wallet::factory()->for($household)->create();

        actingAs($member)->postJson(walletsUrl($household, $wallet, '/archive'))->assertForbidden();

        actingAs($owner)->postJson(walletsUrl($household, $wallet, '/archive'))
            ->assertOk()
            ->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        actingAs($owner)->postJson(walletsUrl($household, $wallet, '/unarchive'))
            ->assertOk()
            ->assertJsonPath('data.archived_at', null);
    });

    it('deletes a wallet without transactions', function () {
        [$household, $owner] = walletHousehold();
        $wallet = Wallet::factory()->for($household)->create();

        actingAs($owner)->deleteJson(walletsUrl($household, $wallet))->assertNoContent();

        expect(Wallet::count())->toBe(0);
    });

    it('refuses to delete a wallet with transactions, even soft-deleted ones', function () {
        [$household, $owner] = walletHousehold();
        $wallet = Wallet::factory()->for($household)->create();
        Transaction::factory()->forWallet($wallet)->create()->delete();

        actingAs($owner)->deleteJson(walletsUrl($household, $wallet))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wallet');

        expect(Wallet::count())->toBe(1);
    });
});
