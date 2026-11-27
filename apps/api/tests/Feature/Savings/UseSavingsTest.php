<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\SavingsAllocation;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Rekening tabungan saldo 20jt, tujuan Liburan memegang 10jt, kategori pengeluaran milik user.
 *
 * @return array{0: User, 1: Account, 2: SavingsGoal, 3: Category}
 */
function useSavingsFixture(int $allocated = 10_000_000): array
{
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->savings()->opening(20_000_000)->create();
    $goal = SavingsGoal::factory()->forAccount($account)->allocated($allocated)->create(['name' => 'Liburan']);
    $category = Category::factory()->forUser($user)->create(['name' => 'Liburan']);

    return [$user, $account, $goal, $category];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function usePayload(Category $category, int $amount, array $overrides = []): array
{
    return array_merge([
        'amount' => $amount, 'category_id' => $category->id, 'occurred_on' => '2026-11-27',
        'note' => 'tiket pesawat', 'client_id' => (string) Str::uuid(),
    ], $overrides);
}

/**
 * Buat transaksi "Gunakan" lewat API, kembalikan id transaksinya.
 */
function useSavingsVia(User $user, SavingsGoal $goal, Category $category, int $amount): int
{
    return (int) actingAs($user)->postJson("/api/savings-goals/{$goal->id}/use", usePayload($category, $amount))
        ->assertCreated()->json('data.transaction.id');
}

it('records an expense and reduces the goal in one step', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();

    $response = actingAs($user)->postJson("/api/savings-goals/{$goal->id}/use", usePayload($category, 4_000_000))
        ->assertCreated()
        ->assertJsonPath('data.transaction.type', 'expense')
        ->assertJsonPath('data.transaction.amount', -4_000_000)
        ->assertJsonPath('data.transaction.account_id', $account->id)
        ->assertJsonPath('data.transaction.category_id', $category->id)
        ->assertJsonPath('data.transaction.note', 'tiket pesawat')
        ->assertJsonPath('data.transaction.savings_goal_id', $goal->id)
        ->assertJsonPath('data.goal.id', $goal->id)
        ->assertJsonPath('data.goal.allocated_amount', 6_000_000);

    $transactionId = $response->json('data.transaction.id');
    $log = SavingsAllocation::ownedBy($user)->where('transaction_id', $transactionId)->sole();

    expect($account->fresh()?->balance)->toBe(16_000_000)
        ->and($goal->fresh()?->allocated_amount)->toBe(6_000_000)
        ->and($log->amount)->toBe(-4_000_000)
        ->and($log->savings_goal_id)->toBe($goal->id);

    // Alokasi turun bersamaan dengan saldo → tidak terpakai.
    actingAs($user)->getJson('/api/savings')->assertOk()->assertJsonPath('data.accounts.0.overdrawn', 0);
    // Daftar & detail transaksi ikut menandai tujuannya.
    actingAs($user)->getJson('/api/transactions')->assertOk()->assertJsonPath('data.0.savings_goal_id', $goal->id);
    actingAs($user)->getJson("/api/transactions/{$transactionId}")->assertOk()->assertJsonPath('data.savings_goal_id', $goal->id);

    assertLedgerBalanced();
    assertSavingsBalanced();
});

it('does not raise the overdrawn notification', function () {
    config(['queue.default' => 'sync']);
    [$user, , $goal, $category] = useSavingsFixture();

    useSavingsVia($user, $goal, $category, 4_000_000);

    expect($user->notifications()->where('type', 'savings_overdrawn')->count())->toBe(0);
});

it('refuses to use more than the goal holds', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();

    actingAs($user)->postJson("/api/savings-goals/{$goal->id}/use", usePayload($category, 10_000_001))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount' => 'Melebihi alokasi tujuan ini (Rp10.000.000)']);

    expect(Transaction::ownedBy($user)->count())->toBe(0)
        ->and($account->fresh()?->balance)->toBe(20_000_000)
        ->and($goal->fresh()?->allocated_amount)->toBe(10_000_000);
    assertSavingsBalanced();
});

it('refuses an income category', function () {
    [$user, , $goal] = useSavingsFixture();
    $income = Category::factory()->forUser($user)->income()->create();

    actingAs($user)->postJson("/api/savings-goals/{$goal->id}/use", usePayload($income, 1_000))
        ->assertUnprocessable()->assertJsonValidationErrors('category_id');

    expect(Transaction::ownedBy($user)->count())->toBe(0);
});

it('replays the same client_id', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();
    $payload = usePayload($category, 4_000_000);

    $first = actingAs($user)->postJson("/api/savings-goals/{$goal->id}/use", $payload)->assertCreated();
    actingAs($user)->postJson("/api/savings-goals/{$goal->id}/use", $payload)
        ->assertOk()
        ->assertJsonPath('data.transaction.id', $first->json('data.transaction.id'))
        ->assertJsonPath('data.transaction.savings_goal_id', $goal->id)
        ->assertJsonPath('data.goal.allocated_amount', 6_000_000);

    expect(Transaction::ownedBy($user)->count())->toBe(1)
        ->and(SavingsAllocation::ownedBy($user)->whereNotNull('transaction_id')->count())->toBe(1)
        ->and($account->fresh()?->balance)->toBe(16_000_000);
    assertLedgerBalanced();
    assertSavingsBalanced();
});

it('refuses a client_id already used by an ordinary transaction', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();
    $clientId = (string) Str::uuid();
    actingAs($user)->postJson('/api/transactions', [
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 1_000, 'category_id' => $category->id,
        'occurred_on' => '2026-11-27', 'client_id' => $clientId,
    ])->assertCreated();

    actingAs($user)->postJson("/api/savings-goals/{$goal->id}/use", usePayload($category, 4_000_000, ['client_id' => $clientId]))
        ->assertUnprocessable()->assertJsonValidationErrors('client_id');

    expect($goal->fresh()?->allocated_amount)->toBe(10_000_000);
});

it('restores the allocation when the transaction is deleted', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();
    $transactionId = useSavingsVia($user, $goal, $category, 10_000_000);
    expect($goal->fresh()?->allocated_amount)->toBe(0);
    actingAs($user)->postJson("/api/savings-goals/{$goal->id}/archive")->assertOk();

    actingAs($user)->deleteJson("/api/transactions/{$transactionId}")->assertNoContent();

    $goal->refresh();
    expect($goal->allocated_amount)->toBe(10_000_000)
        ->and($goal->archived_at)->toBeNull()
        ->and($account->fresh()?->balance)->toBe(20_000_000)
        ->and(SavingsAllocation::ownedBy($user)->where('transaction_id', $transactionId)->exists())->toBeFalse();
    assertLedgerBalanced();
    assertSavingsBalanced();
});

it('unarchives the goal when an edit puts money back', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();
    $transactionId = useSavingsVia($user, $goal, $category, 10_000_000);
    actingAs($user)->postJson("/api/savings-goals/{$goal->id}/archive")->assertOk();

    actingAs($user)->patchJson("/api/transactions/{$transactionId}", [
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 3_000_000,
        'category_id' => $category->id, 'occurred_on' => '2026-11-27',
    ])->assertOk();

    $goal->refresh();
    expect($goal->allocated_amount)->toBe(7_000_000)
        ->and($goal->archived_at)->toBeNull();
    assertLedgerBalanced();
    assertSavingsBalanced();
});

it('adjusts the allocation when the amount is edited', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();
    $transactionId = useSavingsVia($user, $goal, $category, 4_000_000);
    $edit = fn (int $amount) => actingAs($user)->patchJson("/api/transactions/{$transactionId}", [
        'account_id' => $account->id, 'type' => 'expense', 'amount' => $amount,
        'category_id' => $category->id, 'occurred_on' => '2026-11-27',
    ]);

    $edit(3_000_000)->assertOk()->assertJsonPath('data.savings_goal_id', $goal->id);

    expect($goal->fresh()?->allocated_amount)->toBe(7_000_000)
        ->and($account->fresh()?->balance)->toBe(17_000_000)
        ->and(SavingsAllocation::ownedBy($user)->where('transaction_id', $transactionId)->sole()->amount)->toBe(-3_000_000);

    // 20jt > 7jt alokasi + 3jt yang sudah dipakai → alokasi akan negatif.
    $edit(20_000_000)->assertUnprocessable()->assertJsonValidationErrors('amount');

    expect($goal->fresh()?->allocated_amount)->toBe(7_000_000)
        ->and($account->fresh()?->balance)->toBe(17_000_000);
    assertLedgerBalanced();
    assertSavingsBalanced();
});

it('refuses to move a linked transaction to another account', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();
    $other = Account::factory()->forUser($user)->opening(1_000_000)->create();
    $transactionId = useSavingsVia($user, $goal, $category, 4_000_000);

    actingAs($user)->patchJson("/api/transactions/{$transactionId}", [
        'account_id' => $other->id, 'type' => 'expense', 'amount' => 4_000_000,
        'category_id' => $category->id, 'occurred_on' => '2026-11-27',
    ])->assertUnprocessable()->assertJsonValidationErrors('account_id');

    expect(Transaction::ownedBy($user)->find($transactionId)?->account_id)->toBe($account->id)
        ->and($other->fresh()?->balance)->toBe(1_000_000);
    assertLedgerBalanced();
    assertSavingsBalanced();
});

it('refuses to change the type of a linked transaction', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();
    $transactionId = useSavingsVia($user, $goal, $category, 4_000_000);

    actingAs($user)->patchJson("/api/transactions/{$transactionId}", [
        'account_id' => $account->id, 'type' => 'income', 'amount' => 4_000_000, 'occurred_on' => '2026-11-27',
    ])->assertUnprocessable()->assertJsonValidationErrors('type');

    expect($goal->fresh()?->allocated_amount)->toBe(6_000_000);
    assertSavingsBalanced();
});

it('leaves unlinked transactions alone', function () {
    [$user, $account, $goal, $category] = useSavingsFixture();
    $transactionId = (int) actingAs($user)->postJson('/api/transactions', [
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 1_000, 'category_id' => $category->id,
        'occurred_on' => '2026-11-27', 'client_id' => (string) Str::uuid(),
    ])->assertCreated()->assertJsonPath('data.savings_goal_id', null)->json('data.id');

    actingAs($user)->deleteJson("/api/transactions/{$transactionId}")->assertNoContent();

    expect($goal->fresh()?->allocated_amount)->toBe(10_000_000);
    assertSavingsBalanced();
});
