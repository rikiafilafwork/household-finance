<?php

use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\User;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function goalPayload(Account $account, array $overrides = []): array
{
    return array_merge(['account_id' => $account->id, 'name' => 'Dana Darurat', 'target_amount' => 12_000_000], $overrides);
}

describe('creating', function () {
    it('creates a goal that shows up in GET /savings', function () {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->savings()->opening(20_000_000)->create();

        actingAs($user)->postJson('/api/savings-goals', goalPayload($account, ['target_date' => '2027-06-15']))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dana Darurat')
            ->assertJsonPath('data.target_amount', 12_000_000)
            ->assertJsonPath('data.allocated_amount', 0)
            ->assertJsonPath('data.target_date', '2027-06-15')
            ->assertJsonPath('data.percent', 0)
            ->assertJsonPath('data.reached', false);

        actingAs($user)->getJson('/api/savings')
            ->assertOk()
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.account.id', $account->id)
            ->assertJsonPath('data.accounts.0.goals.0.name', 'Dana Darurat');
    });

    it('rejects an account that is not a savings account', function () {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->create();

        actingAs($user)->postJson('/api/savings-goals', goalPayload($account))
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    });

    it('rejects an archived savings account', function () {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->savings()->create(['archived_at' => now()]);

        actingAs($user)->postJson('/api/savings-goals', goalPayload($account))
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    });

    it('rejects a duplicate name', function () {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->savings()->create();
        SavingsGoal::factory()->forAccount($account)->create(['name' => 'Dana Darurat']);

        actingAs($user)->postJson('/api/savings-goals', goalPayload($account))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    });

    it('rejects a non-positive target', function () {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->savings()->create();

        actingAs($user)->postJson('/api/savings-goals', goalPayload($account, ['target_amount' => 0]))
            ->assertUnprocessable()->assertJsonValidationErrors('target_amount');
    });
});

describe('summary', function () {
    it('totals balance, allocated and unallocated across goals', function () {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->savings()->opening(20_000_000)->create();
        SavingsGoal::factory()->forAccount($account)->allocated(10_000_000)->create(['sort_order' => 1]);
        SavingsGoal::factory()->forAccount($account)->allocated(5_000_000)->create(['sort_order' => 2]);
        // Rekening biasa tidak ikut.
        Account::factory()->forUser($user)->opening(9_000_000)->create();

        actingAs($user)->getJson('/api/savings')
            ->assertOk()
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.balance', 20_000_000)
            ->assertJsonPath('data.accounts.0.allocated', 15_000_000)
            ->assertJsonPath('data.accounts.0.unallocated', 5_000_000)
            ->assertJsonPath('data.accounts.0.overdrawn', 0)
            ->assertJsonPath('data.totals', ['balance' => 20_000_000, 'allocated' => 15_000_000, 'unallocated' => 5_000_000, 'overdrawn' => 0]);
    });

    it('reports a negative balance without goals as negative unallocated, not overdrawn', function () {
        $user = User::factory()->create();
        Account::factory()->forUser($user)->savings()->opening(-50_000)->create();

        actingAs($user)->getJson('/api/savings')
            ->assertJsonPath('data.totals.unallocated', -50_000)
            ->assertJsonPath('data.totals.overdrawn', 0);
    });

    it('reports overdrawn when allocations exceed the balance', function () {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->savings()->opening(1_000_000)->create();
        SavingsGoal::factory()->forAccount($account)->allocated(1_500_000)->create();

        actingAs($user)->getJson('/api/savings')
            ->assertJsonPath('data.totals', ['balance' => 1_000_000, 'allocated' => 1_500_000, 'unallocated' => -500_000, 'overdrawn' => 500_000]);
    });

    it('lists active goals by sort_order then name, skips archived ones from the list but counts their money', function () {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->savings()->opening(1_000_000)->create();
        SavingsGoal::factory()->forAccount($account)->create(['name' => 'B', 'sort_order' => 1]);
        SavingsGoal::factory()->forAccount($account)->create(['name' => 'A', 'sort_order' => 1]);
        SavingsGoal::factory()->forAccount($account)->create(['name' => 'Z', 'sort_order' => 0]);
        SavingsGoal::factory()->forAccount($account)->create(['name' => 'Lama', 'archived_at' => now()]);

        actingAs($user)->getJson('/api/savings')->assertJsonPath('data.accounts.0.goals.*.name', ['Z', 'A', 'B']);
    });

    it('skips archived savings accounts', function () {
        $user = User::factory()->create();
        Account::factory()->forUser($user)->savings()->create(['archived_at' => now()]);

        actingAs($user)->getJson('/api/savings')->assertJsonCount(0, 'data.accounts');
    });

    it('computes monthly_needed in the user timezone', function () {
        // 2026-11-30 17:30 UTC = 2026-12-01 00:30 di Jakarta → bulan Desember, jadi tenggat Des = 1 bulan.
        CarbonImmutable::setTestNow('2026-11-30 17:30:00 UTC');
        $user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
        $account = Account::factory()->forUser($user)->savings()->create();
        SavingsGoal::factory()->forAccount($account)->create(['target_amount' => 6_000_000, 'target_date' => '2026-12-31']);

        actingAs($user)->getJson('/api/savings')->assertJsonPath('data.accounts.0.goals.0.monthly_needed', 6_000_000);

        CarbonImmutable::setTestNow();
    });
});

describe('updating', function () {
    it('updates name, target and deadline', function () {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->forAccount(Account::factory()->forUser($user)->savings()->create())->create();

        actingAs($user)->patchJson("/api/savings-goals/{$goal->id}", ['name' => 'Liburan', 'target_amount' => 5_000_000, 'target_date' => null])
            ->assertOk()->assertJsonPath('data.name', 'Liburan')->assertJsonPath('data.target_amount', 5_000_000);
    });

    it('cannot move a goal to another account', function () {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->forAccount(Account::factory()->forUser($user)->savings()->create())->create();
        $other = Account::factory()->forUser($user)->savings()->create();

        actingAs($user)->patchJson("/api/savings-goals/{$goal->id}", ['account_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
    });

    it('rejects a name used by another goal', function () {
        $user = User::factory()->create();
        $account = Account::factory()->forUser($user)->savings()->create();
        SavingsGoal::factory()->forAccount($account)->create(['name' => 'Liburan']);
        $goal = SavingsGoal::factory()->forAccount($account)->create(['name' => 'Laptop']);

        actingAs($user)->patchJson("/api/savings-goals/{$goal->id}", ['name' => 'Liburan'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    });
});

describe('archiving and deleting', function () {
    it('refuses to archive a goal that still holds money', function () {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->forAccount(Account::factory()->forUser($user)->savings()->opening(1_000_000)->create())->allocated(100_000)->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/archive")
            ->assertUnprocessable()->assertJsonValidationErrors('goal');

        expect($goal->fresh()->archived_at)->toBeNull();
    });

    it('archives and unarchives an empty goal', function () {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->forAccount(Account::factory()->forUser($user)->savings()->create())->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/archive")->assertOk();
        expect($goal->fresh()->archived_at)->not->toBeNull();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/unarchive")->assertOk();
        expect($goal->fresh()->archived_at)->toBeNull();
    });

    it('deletes a goal without any history', function () {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->forAccount(Account::factory()->forUser($user)->savings()->create())->create();

        actingAs($user)->deleteJson("/api/savings-goals/{$goal->id}")->assertNoContent();

        expect(SavingsGoal::ownedBy($user)->count())->toBe(0);
    });

    it('refuses to delete a goal that holds money', function () {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->forAccount(Account::factory()->forUser($user)->savings()->opening(1_000_000)->create())->allocated(100_000)->create();

        actingAs($user)->deleteJson("/api/savings-goals/{$goal->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('goal');
    });

    it('refuses to delete a goal that has allocation history even when emptied', function () {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->forAccount(Account::factory()->forUser($user)->savings()->create())->allocated(100_000)->create();
        $goal->update(['allocated_amount' => 0]);

        actingAs($user)->deleteJson("/api/savings-goals/{$goal->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('goal');

        expect(SavingsGoal::ownedBy($user)->count())->toBe(1);
    });
});

describe('achieving', function () {
    it('marks and unmarks a goal as achieved', function () {
        $user = User::factory()->create();
        $goal = SavingsGoal::factory()->forAccount(Account::factory()->forUser($user)->savings()->create())->create();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/achieve")->assertOk();
        expect($goal->fresh()->achieved_at)->not->toBeNull();

        actingAs($user)->postJson("/api/savings-goals/{$goal->id}/unachieve")->assertOk();
        expect($goal->fresh()->achieved_at)->toBeNull();
    });
});

it('does not expose another user’s goals', function () {
    $other = User::factory()->create();
    SavingsGoal::factory()->forAccount(Account::factory()->forUser($other)->savings()->create())->create();
    $user = User::factory()->create();

    actingAs($user)->getJson('/api/savings')->assertJsonCount(0, 'data.accounts');
});
