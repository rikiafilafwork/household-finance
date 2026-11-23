<?php

use App\Models\Account;
use App\Models\User;
use App\Notifications\ReconcileMismatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(fn () => Notification::fake());

/**
 * User dengan transaksi nyata lewat API (bukan factory), jadi saldonya benar.
 *
 * @return array{0: User, 1: Account, 2: Account}
 */
function reconcileSetup(): array
{
    $user = User::factory()->create();
    $bca = Account::factory()->forUser($user)->opening(1_000_000)->create(['name' => 'BCA']);
    $cash = Account::factory()->forUser($user)->cash()->opening(100_000)->create(['name' => 'Dompet']);

    actingAs($user)->postJson('/api/transactions', [
        'account_id' => $bca->id, 'type' => 'expense', 'amount' => 250_000, 'occurred_on' => '2026-11-20', 'client_id' => (string) Str::uuid(),
    ])->assertCreated();
    actingAs($user)->postJson('/api/transfers', [
        'from_account_id' => $bca->id, 'to_account_id' => $cash->id, 'amount' => 50_000, 'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
    ])->assertCreated();
    $deleted = actingAs($user)->postJson('/api/transactions', [
        'account_id' => $cash->id, 'type' => 'expense', 'amount' => 10_000, 'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
    ])->json('data.id');
    actingAs($user)->deleteJson("/api/transactions/{$deleted}")->assertNoContent();

    return [$user, $bca, $cash];
}

it('reports zero difference after real writes and soft deletes', function () {
    reconcileSetup();

    artisan('accounts:reconcile')
        ->expectsOutputToContain('Selisih 0')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('detects a manually corrupted balance and exits with failure', function () {
    [, $bca] = reconcileSetup();
    DB::table('accounts')->where('id', $bca->id)->update(['balance' => 123]);

    artisan('accounts:reconcile')
        ->expectsOutputToContain('Ditemukan 1 akun dengan selisih saldo')
        ->expectsOutputToContain('Rp700.000') // seharusnya: 1.000.000 - 250.000 - 50.000
        ->assertFailed();

    expect($bca->fresh()?->balance)->toBe(123); // tanpa --fix tidak diubah
});

it('fixes the balance with --fix and is clean afterwards', function () {
    [, $bca, $cash] = reconcileSetup();
    DB::table('accounts')->where('id', $bca->id)->update(['balance' => 123]);
    DB::table('accounts')->where('id', $cash->id)->increment('balance', 1);

    artisan('accounts:reconcile --fix')->assertFailed();

    expect($bca->fresh()?->balance)->toBe(700_000)
        ->and($cash->fresh()?->balance)->toBe(150_000);
    assertLedgerBalanced();
    artisan('accounts:reconcile')->assertSuccessful();
});

it('notifies each user in-app about their own accounts only', function () {
    [$user, $bca] = reconcileSetup();
    [$other, $otherBca] = reconcileSetup();
    [$bystander] = reconcileSetup();
    DB::table('accounts')->whereIn('id', [$bca->id, $otherBca->id])->update(['balance' => 0]);

    artisan('accounts:reconcile')->assertFailed();

    Notification::assertSentTo($user, ReconcileMismatch::class, function (ReconcileMismatch $n, array $channels) use ($bca) {
        return array_column($n->mismatches, 'account_id') === [$bca->id]
            && $n->mismatches[0]['expected'] === 700_000
            && $n->mismatches[0]['diff'] === -700_000
            && $n->fixed === false
            && $channels === ['database']; // in-app saja, tanpa email
    });
    Notification::assertSentTo($other, ReconcileMismatch::class, fn (ReconcileMismatch $n) => array_column($n->mismatches, 'account_id') === [$otherBca->id]);
    Notification::assertNotSentTo($bystander, ReconcileMismatch::class);
    Notification::assertSentTimes(ReconcileMismatch::class, 2);
});

it('limits the check to one user with --user', function () {
    [$userA, $bcaA] = reconcileSetup();
    [$userB, $bcaB] = reconcileSetup();
    DB::table('accounts')->where('id', $bcaB->id)->update(['balance' => 0]);

    artisan("accounts:reconcile --user={$userA->id}")->assertSuccessful();
    artisan("accounts:reconcile --user={$userB->id} --fix --no-notify")->assertFailed();

    expect($bcaA->fresh()?->balance)->toBe(700_000)
        ->and($bcaB->fresh()?->balance)->toBe(700_000);
    Notification::assertNothingSent();
});

it('is scheduled daily without --fix', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'accounts:reconcile'));

    expect($event)->not->toBeNull()
        ->and((string) $event?->command)->not->toContain('--fix')
        ->and($event?->expression)->toBe('0 2 * * *')
        ->and($event?->timezone)->toBe('Asia/Jakarta');
});
