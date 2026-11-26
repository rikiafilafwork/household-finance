<?php

use App\Models\Account;
use App\Models\SavingsAllocation;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * @return array{0: User, 1: Account}
 */
function savingsAccount(int $balance): array
{
    $user = User::factory()->create();

    return [$user, Account::factory()->forUser($user)->savings()->opening($balance)->create()];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function allocationPayload(int $amount, array $overrides = []): array
{
    return array_merge(['amount' => $amount, 'occurred_on' => '2026-11-27', 'client_id' => (string) Str::uuid()], $overrides);
}

describe('allocating', function () {
    it('adds and reduces allocation and logs each change', function () {
        [$user, $account] = savingsAccount(5_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(3_000_000, ['note' => 'gajian']))
            ->assertCreated()
            ->assertJsonPath('data.amount', 3_000_000)
            ->assertJsonPath('data.savings_goal_id', $goal->id)
            ->assertJsonPath('data.note', 'gajian');
        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(-500_000))
            ->assertCreated()
            ->assertJsonPath('data.amount', -500_000);

        expect($goal->fresh()?->allocated_amount)->toBe(2_500_000)
            ->and(SavingsAllocation::ownedBy($user)->where('savings_goal_id', $goal->id)->count())->toBe(2)
            // Alokasi hanya label: saldo rekening tidak berubah.
            ->and($account->fresh()?->balance)->toBe(5_000_000);
        assertSavingsBalanced();
    });

    it('refuses to allocate beyond the unallocated balance', function () {
        [$user, $account] = savingsAccount(1_000_000);
        SavingsGoal::factory()->forAccount($account)->allocated(800_000)->create();
        $goal = SavingsGoal::factory()->forAccount($account)->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(300_000))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Melebihi saldo yang belum dialokasikan (Rp200.000)']);

        expect($goal->fresh()?->allocated_amount)->toBe(0);
        assertSavingsBalanced();
    });

    it('allows allocating exactly the unallocated balance', function () {
        [$user, $account] = savingsAccount(1_000_000);
        SavingsGoal::factory()->forAccount($account)->allocated(800_000)->create();
        $goal = SavingsGoal::factory()->forAccount($account)->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(200_000))->assertCreated();

        expect($goal->fresh()?->allocated_amount)->toBe(200_000);
    });

    it('refuses to allocate when the savings balance is zero or negative', function (int $balance) {
        [$user, $account] = savingsAccount($balance);
        $goal = SavingsGoal::factory()->forAccount($account)->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(1))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        expect(SavingsAllocation::ownedBy($user)->count())->toBe(0);
    })->with([0, -50_000]);

    it('refuses to reduce below zero', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->allocated(100_000)->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(-100_001))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        expect($goal->fresh()?->allocated_amount)->toBe(100_000);
        assertSavingsBalanced();
    });

    it('allows reducing while the savings account is overdrawn', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->allocated(1_000_000)->create();
        $account->update(['balance' => 0, 'opening_balance' => 0]);

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(-400_000))->assertCreated();

        expect($goal->fresh()?->allocated_amount)->toBe(600_000);
    });

    it('rejects a zero amount', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(0))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    });

    it('validates the payload', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", [
            'amount' => '1000', 'occurred_on' => 'kemarin', 'note' => str_repeat('a', 256), 'client_id' => 'bukan-uuid',
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'occurred_on', 'note', 'client_id']);

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", ['amount' => 1_000, 'occurred_on' => '2026-11-27'])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    });

    it('refuses to allocate to an archived goal', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->create(['archived_at' => now()]);

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(100_000))
            ->assertUnprocessable()->assertJsonValidationErrors('goal');
    });

    it('refuses to allocate on an account that is no longer a savings account', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->create();
        $account->update(['is_savings' => false]);

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload(100_000))
            ->assertUnprocessable()->assertJsonValidationErrors('goal');
    });

    it('replays the same client_id without a second row', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->create();
        $payload = allocationPayload(250_000);

        $first = actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", $payload)->assertCreated();
        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        expect(SavingsAllocation::ownedBy($user)->count())->toBe(1)
            ->and($goal->fresh()?->allocated_amount)->toBe(250_000);
        assertSavingsBalanced();
    });

    it('replays even after the balance changed so the retry would now be refused', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->create();
        $payload = allocationPayload(1_000_000);

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", $payload)->assertCreated();
        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", $payload)->assertOk();

        expect($goal->fresh()?->allocated_amount)->toBe(1_000_000);
    });
});

describe('history', function () {
    it('lists allocations newest first with cursor pagination', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $goal = SavingsGoal::factory()->forAccount($account)->create();
        $other = SavingsGoal::factory()->forAccount($account)->allocated(10_000)->create();

        foreach (['2026-11-01' => 100_000, '2026-11-20' => 50_000, '2026-11-10' => -30_000] as $date => $amount) {
            actingAs($user)->postJson("/api/savings-goals/{$goal->id}/allocations", allocationPayload($amount, ['occurred_on' => $date]))->assertCreated();
        }

        $page = actingAs($user)->getJson("/api/savings-goals/{$goal->id}/allocations?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.occurred_on', '2026-11-20')
            ->assertJsonPath('data.1.occurred_on', '2026-11-10');

        $cursor = $page->json('meta.next_cursor');
        expect($cursor)->toBeString();

        actingAs($user)->getJson("/api/savings-goals/{$goal->id}/allocations?per_page=2&cursor={$cursor}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.occurred_on', '2026-11-01')
            ->assertJsonPath('data.0.amount', 100_000)
            // Riwayat tujuan lain (10 rb) tidak ikut.
            ->assertJsonPath('meta.next_cursor', null);

        actingAs($user)->getJson("/api/savings-goals/{$other->id}/allocations")->assertJsonCount(1, 'data');
    });
});

describe('moving', function () {
    it('moves allocation between goals atomically even while overdrawn', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $a = SavingsGoal::factory()->forAccount($account)->allocated(600_000)->create();
        $b = SavingsGoal::factory()->forAccount($account)->allocated(400_000)->create();

        // Pengeluaran dari rekening tabungan → saldo 700 rb < total alokasi 1 jt (terpakai).
        actingAs($user)->postJson('/api/transactions', [
            'account_id' => $account->id, 'type' => 'expense', 'amount' => 300_000,
            'occurred_on' => '2026-11-27', 'client_id' => (string) Str::uuid(),
        ])->assertCreated();

        $response = actingAs($user)->postJson("/api/savings-goals/{$a->id}/move", [
            'to_goal_id' => $b->id, 'amount' => 100_000, 'note' => 'geser', 'client_id' => (string) Str::uuid(),
        ])->assertCreated()
            ->assertJsonPath('data.savings_goal_id', $a->id)
            ->assertJsonPath('data.amount', -100_000);

        expect($a->fresh()?->allocated_amount)->toBe(500_000)
            ->and($b->fresh()?->allocated_amount)->toBe(500_000);

        $rows = SavingsAllocation::ownedBy($user)->whereNotNull('move_group_id')->orderBy('id')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('move_group_id')->unique())->toHaveCount(1)
            ->and($rows->pluck('amount')->all())->toBe([-100_000, 100_000])
            ->and($rows->pluck('savings_goal_id')->all())->toBe([$a->id, $b->id])
            // client_id hanya di baris keluar (unik per user).
            ->and($rows->pluck('client_id')->all())->toBe([$response->json('data.client_id'), null]);
        assertSavingsBalanced();
        assertLedgerBalanced();
    });

    it('replays a move with the same client_id', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $a = SavingsGoal::factory()->forAccount($account)->allocated(600_000)->create();
        $b = SavingsGoal::factory()->forAccount($account)->create();
        $payload = ['to_goal_id' => $b->id, 'amount' => 100_000, 'client_id' => (string) Str::uuid()];

        $first = actingAs($user)->postJson("/api/savings-goals/{$a->id}/move", $payload)->assertCreated();
        actingAs($user)->postJson("/api/savings-goals/{$a->id}/move", $payload)
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        expect([$a->fresh()?->allocated_amount, $b->fresh()?->allocated_amount])->toBe([500_000, 100_000]);
        assertSavingsBalanced();
    });

    it('refuses to move more than the source goal holds', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $a = SavingsGoal::factory()->forAccount($account)->allocated(100_000)->create();
        $b = SavingsGoal::factory()->forAccount($account)->create();

        actingAs($user)->postJson("/api/savings-goals/{$a->id}/move", ['to_goal_id' => $b->id, 'amount' => 100_001, 'client_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        expect(SavingsAllocation::ownedBy($user)->whereNotNull('move_group_id')->count())->toBe(0);
    });

    it('refuses to move to a goal on another savings account', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $otherAccount = Account::factory()->forUser($user)->savings()->opening(1_000_000)->create();
        $a = SavingsGoal::factory()->forAccount($account)->allocated(100_000)->create();
        $b = SavingsGoal::factory()->forAccount($otherAccount)->create();

        actingAs($user)->postJson("/api/savings-goals/{$a->id}/move", ['to_goal_id' => $b->id, 'amount' => 50_000, 'client_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('to_goal_id');

        expect([$a->fresh()?->allocated_amount, $b->fresh()?->allocated_amount])->toBe([100_000, 0]);
    });

    it('refuses to move to an archived goal or to the same goal', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $a = SavingsGoal::factory()->forAccount($account)->allocated(100_000)->create();
        $archived = SavingsGoal::factory()->forAccount($account)->create(['archived_at' => now()]);

        actingAs($user)->postJson("/api/savings-goals/{$a->id}/move", ['to_goal_id' => $archived->id, 'amount' => 50_000, 'client_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('to_goal_id');
        actingAs($user)->postJson("/api/savings-goals/{$a->id}/move", ['to_goal_id' => $a->id, 'amount' => 50_000, 'client_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('to_goal_id');
    });

    it('requires a positive amount', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $a = SavingsGoal::factory()->forAccount($account)->allocated(100_000)->create();
        $b = SavingsGoal::factory()->forAccount($account)->create();

        actingAs($user)->postJson("/api/savings-goals/{$a->id}/move", ['to_goal_id' => $b->id, 'amount' => -1, 'client_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    });

    it('refuses to reuse an allocation client_id for a move', function () {
        [$user, $account] = savingsAccount(1_000_000);
        $a = SavingsGoal::factory()->forAccount($account)->create();
        $b = SavingsGoal::factory()->forAccount($account)->create();
        $clientId = (string) Str::uuid();

        actingAs($user)->postJson("/api/savings-goals/{$a->id}/allocations", allocationPayload(100_000, ['client_id' => $clientId]))->assertCreated();
        actingAs($user)->postJson("/api/savings-goals/{$a->id}/move", ['to_goal_id' => $b->id, 'amount' => 50_000, 'client_id' => $clientId])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
        actingAs($user)->postJson("/api/savings-goals/{$b->id}/allocations", allocationPayload(1, ['client_id' => $clientId]))
            ->assertOk(); // replay baris lama, bukan baris baru

        expect(SavingsAllocation::ownedBy($user)->count())->toBe(1);
    });
});
