<?php

use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('marks an account as savings', function () {
    $account = Account::factory()->create();

    actingAs(User::find($account->user_id))->patchJson("/api/accounts/{$account->id}", ['is_savings' => true])
        ->assertOk()
        ->assertJsonPath('data.is_savings', true);

    expect($account->fresh()->is_savings)->toBeTrue();
});

it('creates an account as savings', function () {
    actingAs(User::factory()->create())->postJson('/api/accounts', ['name' => 'Jago Tabungan', 'type' => 'bank', 'is_savings' => true])
        ->assertCreated()
        ->assertJsonPath('data.is_savings', true);
});

it('defaults new accounts to not savings', function () {
    actingAs(User::factory()->create())->postJson('/api/accounts', ['name' => 'BCA', 'type' => 'bank'])
        ->assertCreated()
        ->assertJsonPath('data.is_savings', false);
});

it('ignores savings_overdrawn_at from requests', function () {
    $account = Account::factory()->savings()->create();

    actingAs(User::find($account->user_id))->patchJson("/api/accounts/{$account->id}", ['savings_overdrawn_at' => '2026-11-30 10:00:00'])
        ->assertOk()
        ->assertJsonMissingPath('data.savings_overdrawn_at');

    expect($account->fresh()->savings_overdrawn_at)->toBeNull();
});

it('refuses to unmark savings while a goal holds money', function () {
    $account = Account::factory()->savings()->create();
    SavingsGoal::factory()->forAccount($account)->allocated(100_000)->create();

    actingAs(User::find($account->user_id))->patchJson("/api/accounts/{$account->id}", ['is_savings' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_savings');

    expect($account->fresh()->is_savings)->toBeTrue();
});

it('refuses to archive a savings account while a goal holds money', function () {
    $account = Account::factory()->savings()->create();
    SavingsGoal::factory()->forAccount($account)->allocated(100_000)->create();

    actingAs(User::find($account->user_id))->postJson("/api/accounts/{$account->id}/archive")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    expect($account->fresh()->archived_at)->toBeNull();
});

it('allows unmarking and archiving when all goals are empty', function () {
    $account = Account::factory()->savings()->create();
    SavingsGoal::factory()->forAccount($account)->allocated(0)->create();
    $user = User::find($account->user_id);

    actingAs($user)->patchJson("/api/accounts/{$account->id}", ['is_savings' => false])
        ->assertOk()->assertJsonPath('data.is_savings', false);

    $other = Account::factory()->savings()->forUser($user)->create();
    actingAs($user)->postJson("/api/accounts/{$other->id}/archive")->assertOk();
});

it('refuses to unmark savings with falsy non-boolean values while a goal holds money', function (mixed $value) {
    $account = Account::factory()->savings()->create();
    SavingsGoal::factory()->forAccount($account)->allocated(100_000)->create();

    actingAs(User::find($account->user_id))->patchJson("/api/accounts/{$account->id}", ['is_savings' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_savings');

    expect($account->fresh()->is_savings)->toBeTrue();
})->with([0, '0']);

it('refuses to delete an account that has a savings goal', function () {
    $account = Account::factory()->savings()->create();
    SavingsGoal::factory()->forAccount($account)->allocated(0)->create();

    actingAs(User::find($account->user_id))->deleteJson("/api/accounts/{$account->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    expect(Account::ownedBy($account->user_id)->whereKey($account->id)->exists())->toBeTrue();
});
