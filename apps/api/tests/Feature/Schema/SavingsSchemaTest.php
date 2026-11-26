<?php

use App\Models\Account;
use App\Models\SavingsAllocation;
use App\Models\SavingsGoal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @param array<string, mixed> $overrides */
function insertGoal(array $overrides = []): void
{
    $account = Account::factory()->savings()->create();

    DB::table('savings_goals')->insert(array_merge([
        'user_id' => $account->user_id, 'account_id' => $account->id, 'name' => 'Liburan',
        'target_amount' => 1_000_000, 'allocated_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
    ], $overrides));
}

it('rejects target_amount 0', function () {
    insertGoal(['target_amount' => 0]);
})->throws(QueryException::class, 'chk_goal_target');

it('rejects negative allocated_amount', function () {
    insertGoal(['allocated_amount' => -1]);
})->throws(QueryException::class, 'chk_goal_allocated');

it('rejects an allocation with amount 0', function () {
    $goal = SavingsGoal::factory()->create();

    DB::table('savings_allocations')->insert([
        'user_id' => $goal->user_id, 'savings_goal_id' => $goal->id, 'amount' => 0,
        'occurred_on' => '2026-11-30', 'created_at' => now(), 'updated_at' => now(),
    ]);
})->throws(QueryException::class, 'chk_alloc_nonzero');

it('rejects duplicate goal names per user', function () {
    $account = Account::factory()->savings()->create();
    SavingsGoal::factory()->forAccount($account)->create(['name' => 'Liburan']);

    SavingsGoal::factory()->forAccount($account)->create(['name' => 'Liburan']);
})->throws(QueryException::class, 'uq_savings_goal_name');

it('keeps allocated_amount equal to the allocation log when using the factory', function () {
    $goal = SavingsGoal::factory()->allocated(75_000)->create();

    $empty = SavingsGoal::factory()->allocated(0)->create();

    expect((int) SavingsAllocation::ownedBy($goal->user_id)->where('savings_goal_id', $goal->id)->sum('amount'))->toBe(75_000)
        ->and(SavingsAllocation::ownedBy($empty->user_id)->where('savings_goal_id', $empty->id)->count())->toBe(0);
});
