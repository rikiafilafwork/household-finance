<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * @return array{owner: User, bca: Account, cash: Account, savings: Account}
 */
function transferSetup(): array
{
    $owner = User::factory()->create();

    return [
        'owner' => $owner,
        'bca' => Account::factory()->forUser($owner)->opening(5_000_000)->create(['name' => 'BCA']),
        'cash' => Account::factory()->forUser($owner)->cash()->opening(100_000)->create(['name' => 'Dompet']),
        'savings' => Account::factory()->forUser($owner)->opening(0)->create(['name' => 'Tabungan']),
    ];
}

function transfersUrl(?string $groupId = null): string
{
    return '/api/transfers'.($groupId ? "/{$groupId}" : '');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function transferPayload(Account $from, Account $to, array $overrides = []): array
{
    return array_merge([
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount' => 500_000,
        'occurred_on' => '2026-11-21',
        'note' => 'Tarik tunai',
        'client_id' => (string) Str::uuid(),
    ], $overrides);
}

/**
 * @param  array{bca: Account, cash: Account, savings: Account}  $s
 * @param  array{0: int, 1: int, 2: int}  $expected
 */
function expectBalances(array $s, array $expected): void
{
    expect([$s['bca']->fresh()?->balance, $s['cash']->fresh()?->balance, $s['savings']->fresh()?->balance])->toBe($expected);
    assertLedgerBalanced();
}

describe('create', function () {
    it('writes two paired rows and moves money between accounts', function () {
        $s = transferSetup();

        $response = actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($s['bca'], $s['cash']))
            ->assertCreated()
            ->assertJsonPath('data.amount', 500_000)
            ->assertJsonPath('data.legs.out.amount', -500_000)
            ->assertJsonPath('data.legs.in.amount', 500_000);

        $legs = Transaction::where('transfer_group_id', $response->json('data.transfer_group_id'))->orderBy('id')->get();
        expect($legs)->toHaveCount(2)
            ->and($legs->pluck('type')->all())->toBe([TransactionType::Transfer, TransactionType::Transfer])
            ->and($legs->pluck('category_id')->all())->toBe([null, null])
            ->and($legs->pluck('account_id')->all())->toBe([$s['bca']->id, $s['cash']->id]);

        expectBalances($s, [4_500_000, 600_000, 0]);
    });

    it('does not count as income or expense', function () {
        $s = transferSetup();
        actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($s['bca'], $s['savings']))->assertCreated();

        $incomeExpense = Transaction::whereIn('type', ['income', 'expense'])->sum('amount');
        expect((int) $incomeExpense)->toBe(0)
            ->and((int) Transaction::sum('amount'))->toBe(0); // dua sisi saling meniadakan
    });

    it('validates the transfer', function (string $case, string $field) {
        $s = transferSetup();
        $payload = match ($case) {
            'same account' => transferPayload($s['bca'], $s['bca']),
            'archived source' => transferPayload(Account::factory()->forUser($s['owner'])->archived()->create(), $s['cash']),
            'zero amount' => transferPayload($s['bca'], $s['cash'], ['amount' => 0]),
            'negative amount' => transferPayload($s['bca'], $s['cash'], ['amount' => -500_000]),
            'string amount' => transferPayload($s['bca'], $s['cash'], ['amount' => '500.000']),
            default => throw new LogicException($case),
        };

        actingAs($s['owner'])->postJson(transfersUrl(), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Transaction::ownedBy($s['owner'])->count())->toBe(0);
        expectBalances($s, [5_000_000, 100_000, 0]);
    })->with([
        ['same account', 'to_account_id'],
        ['archived source', 'from_account_id'],
        ['zero amount', 'amount'],
        ['negative amount', 'amount'],
        ['string amount', 'amount'],
    ]);

});

describe('update', function () {
    it('changes the amount on both legs', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($group), ['amount' => 750_000])
            ->assertOk()
            ->assertJsonPath('data.legs.out.amount', -750_000)
            ->assertJsonPath('data.legs.in.amount', 750_000);

        expectBalances($s, [4_250_000, 850_000, 0]);
    });

    it('changes the destination account', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($group), ['to_account_id' => $s['savings']->id])
            ->assertOk()
            ->assertJsonPath('data.to_account_id', $s['savings']->id);

        expectBalances($s, [4_500_000, 100_000, 500_000]);
    });

    it('reverses the direction of a transfer', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($group), [
            'from_account_id' => $s['cash']->id,
            'to_account_id' => $s['bca']->id,
            'amount' => 50_000,
        ])->assertOk();

        expectBalances($s, [5_050_000, 50_000, 0]);
    });

    it('moves both ends to entirely different accounts (four accounts locked)', function () {
        $s = transferSetup();
        $extra = Account::factory()->forUser($s['owner'])->opening(1_000_000)->create();
        $group = actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($group), [
            'from_account_id' => $extra->id,
            'to_account_id' => $s['savings']->id,
        ])->assertOk();

        expect($extra->fresh()?->balance)->toBe(500_000);
        expectBalances($s, [5_000_000, 100_000, 500_000]);
    });

    it('rejects an update that makes both ends the same account', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->patchJson(transfersUrl($group), ['to_account_id' => $s['bca']->id])
            ->assertUnprocessable();

        expectBalances($s, [4_500_000, 600_000, 0]);
    });

});

describe('delete', function () {
    it('soft deletes both legs and restores both balances', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->deleteJson(transfersUrl($group))->assertNoContent();

        expect(Transaction::where('transfer_group_id', $group)->count())->toBe(0)
            ->and(Transaction::withTrashed()->where('transfer_group_id', $group)->count())->toBe(2);
        expectBalances($s, [5_000_000, 100_000, 0]);

        actingAs($s['owner'])->getJson(transfersUrl($group))->assertNotFound();
    });

    it('cannot delete one leg through the transactions endpoint', function () {
        $s = transferSetup();
        $group = actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($s['bca'], $s['cash']))->json('data.transfer_group_id');
        $leg = Transaction::where('transfer_group_id', $group)->firstOrFail();

        actingAs($s['owner'])->deleteJson("/api/transactions/{$leg->id}")->assertUnprocessable();
        actingAs($s['owner'])->patchJson("/api/transactions/{$leg->id}", ['amount' => 1])->assertUnprocessable();

        expectBalances($s, [4_500_000, 600_000, 0]);
    });
});

describe('isolation', function () {
    it('returns 404 for another user’s transfer and changes nothing', function () {
        $s = transferSetup();
        $other = transferSetup();
        $group = actingAs($other['owner'])->postJson(transfersUrl(), transferPayload($other['bca'], $other['cash']))->json('data.transfer_group_id');

        actingAs($s['owner'])->getJson(transfersUrl($group))->assertNotFound();
        actingAs($s['owner'])->patchJson(transfersUrl($group), ['amount' => 1])->assertNotFound();
        actingAs($s['owner'])->deleteJson(transfersUrl($group))->assertNotFound();

        expectBalances($other, [4_500_000, 600_000, 0]);
    });

    it('returns 404 when either end is another user’s account', function (string $end) {
        $s = transferSetup();
        $foreign = Account::factory()->opening(1_000_000)->create();
        $payload = $end === 'from' ? transferPayload($foreign, $s['cash']) : transferPayload($s['bca'], $foreign);

        actingAs($s['owner'])->postJson(transfersUrl(), $payload)->assertNotFound();

        expect($foreign->fresh()?->balance)->toBe(1_000_000);
        expectBalances($s, [5_000_000, 100_000, 0]);
    })->with(['from', 'to']);
});

it('survives many transfers back and forth with a consistent ledger', function () {
    $s = transferSetup();
    $groups = [];

    foreach (range(1, 10) as $i) {
        [$from, $to] = $i % 2 ? [$s['bca'], $s['cash']] : [$s['cash'], $s['bca']];
        $groups[] = actingAs($s['owner'])->postJson(transfersUrl(), transferPayload($from, $to, ['amount' => $i * 1_000]))
            ->assertCreated()->json('data.transfer_group_id');
    }
    actingAs($s['owner'])->patchJson(transfersUrl($groups[0]), ['to_account_id' => $s['savings']->id])->assertOk();
    actingAs($s['owner'])->deleteJson(transfersUrl($groups[1]))->assertNoContent();

    assertLedgerBalanced();
    expect((int) Transaction::sum('amount'))->toBe(0);
});
