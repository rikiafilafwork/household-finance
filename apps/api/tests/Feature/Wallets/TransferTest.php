<?php

use App\Enums\HouseholdRole;
use App\Enums\TransactionType;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;

use function Pest\Laravel\actingAs;

/**
 * @return array{household: Household, owner: User, member: User, bca: Wallet, cash: Wallet, savings: Wallet}
 */
function transferSetup(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);

    return [
        'household' => $household,
        'owner' => $owner,
        'member' => $member,
        'bca' => Wallet::factory()->for($household)->opening(5_000_000)->create(['name' => 'BCA']),
        'cash' => Wallet::factory()->for($household)->cash()->opening(100_000)->create(['name' => 'Dompet']),
        'savings' => Wallet::factory()->for($household)->opening(0)->create(['name' => 'Tabungan']),
    ];
}

function transfersUrl(Household $household, ?string $groupId = null): string
{
    return "/api/households/{$household->id}/transfers".($groupId ? "/{$groupId}" : '');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function transferPayload(Wallet $from, Wallet $to, array $overrides = []): array
{
    return array_merge([
        'from_wallet_id' => $from->id,
        'to_wallet_id' => $to->id,
        'amount' => 500_000,
        'occurred_on' => '2026-11-21',
        'note' => 'Tarik tunai',
    ], $overrides);
}

/**
 * @param  array{bca: Wallet, cash: Wallet, savings: Wallet}  $s
 * @param  array{0: int, 1: int, 2: int}  $expected
 */
function expectBalances(array $s, array $expected): void
{
    expect([$s['bca']->fresh()?->balance, $s['cash']->fresh()?->balance, $s['savings']->fresh()?->balance])->toBe($expected);
    assertLedgerBalanced();
}

describe('create', function () {
    it('writes two paired rows and moves money between wallets', function () {
        $s = transferSetup();

        $response = actingAs($s['member'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['cash']))
            ->assertCreated()
            ->assertJsonPath('data.amount', 500_000)
            ->assertJsonPath('data.legs.out.amount', -500_000)
            ->assertJsonPath('data.legs.in.amount', 500_000);

        $legs = Transaction::where('transfer_group_id', $response->json('data.transfer_group_id'))->orderBy('id')->get();
        expect($legs)->toHaveCount(2)
            ->and($legs->pluck('type')->all())->toBe([TransactionType::Transfer, TransactionType::Transfer])
            ->and($legs->pluck('category_id')->all())->toBe([null, null])
            ->and($legs->pluck('wallet_id')->all())->toBe([$s['bca']->id, $s['cash']->id]);

        expectBalances($s, [4_500_000, 600_000, 0]);
    });

    it('does not count as income or expense', function () {
        $s = transferSetup();
        actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['savings']))->assertCreated();

        $incomeExpense = Transaction::whereIn('type', ['income', 'expense'])->sum('amount');
        expect((int) $incomeExpense)->toBe(0)
            ->and((int) Transaction::sum('amount'))->toBe(0); // dua sisi saling meniadakan
    });

    it('validates the transfer', function (string $case, string $field) {
        $s = transferSetup();
        $payload = match ($case) {
            'same wallet' => transferPayload($s['bca'], $s['bca']),
            'foreign destination' => transferPayload($s['bca'], Wallet::factory()->create()),
            'archived source' => transferPayload(Wallet::factory()->for($s['household'])->archived()->create(), $s['cash']),
            'zero amount' => transferPayload($s['bca'], $s['cash'], ['amount' => 0]),
            'negative amount' => transferPayload($s['bca'], $s['cash'], ['amount' => -500_000]),
            'string amount' => transferPayload($s['bca'], $s['cash'], ['amount' => '500.000']),
            default => throw new LogicException($case),
        };

        actingAs($s['owner'])->postJson(transfersUrl($s['household']), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Transaction::count())->toBe(0);
        expectBalances($s, [5_000_000, 100_000, 0]);
    })->with([
        ['same wallet', 'to_wallet_id'],
        ['foreign destination', 'to_wallet_id'],
        ['archived source', 'from_wallet_id'],
        ['zero amount', 'amount'],
        ['negative amount', 'amount'],
        ['string amount', 'amount'],
    ]);

    it('can move money from a private wallet into a shared one', function () {
        $s = transferSetup();
        $mine = Wallet::factory()->for($s['household'])->privateTo($s['member'])->opening(300_000)->create();

        actingAs($s['member'])->postJson(transfersUrl($s['household']), transferPayload($mine, $s['cash'], ['amount' => 50_000]))
            ->assertCreated();

        expect($mine->fresh()?->balance)->toBe(250_000);
        expectBalances($s, [5_000_000, 150_000, 0]);
    });

    it('cannot pull money out of someone else’s private wallet', function () {
        $s = transferSetup();
        $ownersPrivate = Wallet::factory()->for($s['household'])->privateTo($s['owner'])->opening(1_000_000)->create();

        actingAs($s['member'])->postJson(transfersUrl($s['household']), transferPayload($ownersPrivate, $s['cash']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_wallet_id');
    });
});

describe('update', function () {
    it('changes the amount on both legs', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($s['household'], $group), ['amount' => 750_000])
            ->assertOk()
            ->assertJsonPath('data.legs.out.amount', -750_000)
            ->assertJsonPath('data.legs.in.amount', 750_000);

        expectBalances($s, [4_250_000, 850_000, 0]);
    });

    it('changes the destination wallet', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($s['household'], $group), ['to_wallet_id' => $s['savings']->id])
            ->assertOk()
            ->assertJsonPath('data.to_wallet_id', $s['savings']->id);

        expectBalances($s, [4_500_000, 100_000, 500_000]);
    });

    it('reverses the direction of a transfer', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($s['household'], $group), [
            'from_wallet_id' => $s['cash']->id,
            'to_wallet_id' => $s['bca']->id,
            'amount' => 50_000,
        ])->assertOk();

        expectBalances($s, [5_050_000, 50_000, 0]);
    });

    it('moves both ends to entirely different wallets (four wallets locked)', function () {
        $s = transferSetup();
        $extra = Wallet::factory()->for($s['household'])->opening(1_000_000)->create();
        $group = actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($s['household'], $group), [
            'from_wallet_id' => $extra->id,
            'to_wallet_id' => $s['savings']->id,
        ])->assertOk();

        expect($extra->fresh()?->balance)->toBe(500_000);
        expectBalances($s, [5_000_000, 100_000, 500_000]);
    });

    it('rejects an update that makes both ends the same wallet', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($s['household'], $group), ['to_wallet_id' => $s['bca']->id])
            ->assertUnprocessable();

        expectBalances($s, [4_500_000, 600_000, 0]);
    });

    it('lets members edit only their own transfers', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['member'])->patchJson(transfersUrl($s['household'], $group), ['amount' => 1])->assertForbidden();
        expectBalances($s, [4_500_000, 600_000, 0]);
    });
});

describe('delete', function () {
    it('soft deletes both legs and restores both balances', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->deleteJson(transfersUrl($s['household'], $group))->assertNoContent();

        expect(Transaction::where('transfer_group_id', $group)->count())->toBe(0)
            ->and(Transaction::withTrashed()->where('transfer_group_id', $group)->count())->toBe(2);
        expectBalances($s, [5_000_000, 100_000, 0]);

        actingAs($s['owner'])->getJson(transfersUrl($s['household'], $group))->assertNotFound();
    });

    it('cannot delete one leg through the transactions endpoint', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');
        $leg = Transaction::where('transfer_group_id', $group)->firstOrFail();

        actingAs($s['owner'])->deleteJson("/api/households/{$s['household']->id}/transactions/{$leg->id}")->assertUnprocessable();
        actingAs($s['owner'])->patchJson("/api/households/{$s['household']->id}/transactions/{$leg->id}", ['amount' => 1])->assertUnprocessable();

        expectBalances($s, [4_500_000, 600_000, 0]);
    });
});

describe('visibility', function () {
    it('hides transfers that touch a private wallet the user cannot see', function () {
        $s = transferSetup();
        $mine = Wallet::factory()->for($s['household'])->privateTo($s['member'])->opening(300_000)->create();
        $group = actingAs($s['member'])->postJson(transfersUrl($s['household']), transferPayload($mine, $s['cash'], ['amount' => 50_000]))->json('data.transfer_group_id');

        actingAs($s['member'])->getJson(transfersUrl($s['household'], $group))->assertOk();
        actingAs($s['owner'])->getJson(transfersUrl($s['household'], $group))->assertForbidden();
        actingAs($s['owner'])->deleteJson(transfersUrl($s['household'], $group))->assertForbidden();
    });

    it('scopes transfers to their household', function () {
        $s = transferSetup();
        $other = transferSetup();
        $group = actingAs($other['owner'])->postJson(transfersUrl($other['household']), transferPayload($other['bca'], $other['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->getJson(transfersUrl($s['household'], $group))->assertNotFound();
    });
});

it('survives many transfers back and forth with a consistent ledger', function () {
    $s = transferSetup();
    $groups = [];

    foreach (range(1, 10) as $i) {
        [$from, $to] = $i % 2 ? [$s['bca'], $s['cash']] : [$s['cash'], $s['bca']];
        $groups[] = actingAs($s['owner'])->postJson(transfersUrl($s['household']), transferPayload($from, $to, ['amount' => $i * 1_000]))
            ->assertCreated()->json('data.transfer_group_id');
    }
    actingAs($s['owner'])->patchJson(transfersUrl($s['household'], $groups[0]), ['to_wallet_id' => $s['savings']->id])->assertOk();
    actingAs($s['owner'])->deleteJson(transfersUrl($s['household'], $groups[1]))->assertNoContent();

    assertLedgerBalanced();
    expect((int) Transaction::sum('amount'))->toBe(0);
});
