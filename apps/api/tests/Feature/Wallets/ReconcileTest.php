<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\ReconcileMismatch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(fn () => Notification::fake());

/**
 * Household dengan transaksi nyata lewat API (bukan factory), jadi saldonya benar.
 *
 * @return array{0: Household, 1: User, 2: User, 3: Wallet, 4: Wallet}
 */
function reconcileSetup(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $household = Household::factory()->withOwner($owner)->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member, 'joined_at' => now()]);
    $bca = Wallet::factory()->for($household)->opening(1_000_000)->create(['name' => 'BCA']);
    $cash = Wallet::factory()->for($household)->cash()->opening(100_000)->create(['name' => 'Dompet']);

    $base = "/api/households/{$household->id}";
    actingAs($owner)->postJson("{$base}/transactions", [
        'wallet_id' => $bca->id, 'type' => 'expense', 'amount' => 250_000, 'occurred_on' => '2026-11-20', 'client_id' => (string) Str::uuid(),
    ])->assertCreated();
    actingAs($owner)->postJson("{$base}/transfers", [
        'from_wallet_id' => $bca->id, 'to_wallet_id' => $cash->id, 'amount' => 50_000, 'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
    ])->assertCreated();
    $deleted = actingAs($owner)->postJson("{$base}/transactions", [
        'wallet_id' => $cash->id, 'type' => 'expense', 'amount' => 10_000, 'occurred_on' => '2026-11-21', 'client_id' => (string) Str::uuid(),
    ])->json('data.id');
    actingAs($owner)->deleteJson("{$base}/transactions/{$deleted}")->assertNoContent();
    Transaction::factory()->forWallet($cash)->expense(999_000)->pending()->create(); // pending tidak dihitung

    return [$household, $owner, $member, $bca, $cash];
}

it('reports zero difference after real writes, pending and soft deletes', function () {
    reconcileSetup();

    artisan('wallets:reconcile')
        ->expectsOutputToContain('Selisih 0')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('detects a manually corrupted balance and exits with failure', function () {
    [, , , $bca] = reconcileSetup();
    DB::table('wallets')->where('id', $bca->id)->update(['balance' => 123]);

    artisan('wallets:reconcile')
        ->expectsOutputToContain('Ditemukan 1 wallet dengan selisih saldo')
        ->expectsOutputToContain('Rp700.000') // seharusnya: 1.000.000 - 250.000 - 50.000
        ->assertFailed();

    expect($bca->fresh()?->balance)->toBe(123); // tanpa --fix tidak diubah
});

it('fixes the balance with --fix and is clean afterwards', function () {
    [, , , $bca, $cash] = reconcileSetup();
    DB::table('wallets')->where('id', $bca->id)->update(['balance' => 123]);
    DB::table('wallets')->where('id', $cash->id)->increment('balance', 1);

    artisan('wallets:reconcile --fix')->assertFailed();

    expect($bca->fresh()?->balance)->toBe(700_000)
        ->and($cash->fresh()?->balance)->toBe(150_000);
    assertLedgerBalanced();
    artisan('wallets:reconcile')->assertSuccessful();
});

it('notifies only the owners of the affected household', function () {
    [$household, $owner, $member, $bca] = reconcileSetup();
    [, $otherOwner] = reconcileSetup();
    DB::table('wallets')->where('id', $bca->id)->update(['balance' => 0]);

    artisan('wallets:reconcile')->assertFailed();

    Notification::assertSentTo($owner, ReconcileMismatch::class, function (ReconcileMismatch $n) use ($household, $bca) {
        return $n->household->is($household)
            && $n->mismatches[0]['wallet_id'] === $bca->id
            && $n->mismatches[0]['expected'] === 700_000
            && $n->mismatches[0]['diff'] === -700_000
            && $n->fixed === false;
    });
    Notification::assertNotSentTo($member, ReconcileMismatch::class);
    Notification::assertNotSentTo($otherOwner, ReconcileMismatch::class);
});

it('never reveals a private wallet mismatch to the household owner, only to the wallet owner', function () {
    [$household, $owner, $member, $bca] = reconcileSetup();
    $private = Wallet::factory()->for($household)->privateTo($member)->opening(10_000)->create(['name' => 'Tabungan Rahasia']);
    DB::table('wallets')->whereIn('id', [$bca->id, $private->id])->update(['balance' => 1]);

    artisan('wallets:reconcile')->assertFailed();

    Notification::assertSentTo($owner, ReconcileMismatch::class, fn (ReconcileMismatch $n) => array_column($n->mismatches, 'wallet_id') === [$bca->id]
        && ! str_contains($n->toMail($owner)->render()->toHtml(), 'Tabungan Rahasia'));
    Notification::assertSentTo($member, ReconcileMismatch::class, fn (ReconcileMismatch $n) => array_column($n->mismatches, 'wallet_id') === [$private->id]);
    Notification::assertSentTimes(ReconcileMismatch::class, 2);
});

it('limits the check to one household with --household', function () {
    [$householdA, , , $bcaA] = reconcileSetup();
    [$householdB, , , $bcaB] = reconcileSetup();
    DB::table('wallets')->where('id', $bcaB->id)->update(['balance' => 0]);

    artisan("wallets:reconcile --household={$householdA->id}")->assertSuccessful();
    artisan("wallets:reconcile --household={$householdB->id} --fix --no-notify")->assertFailed();

    expect($bcaA->fresh()?->balance)->toBe(700_000)
        ->and($bcaB->fresh()?->balance)->toBe(700_000);
    Notification::assertNothingSent();
});

it('is scheduled daily without --fix', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'wallets:reconcile'));

    expect($event)->not->toBeNull()
        ->and((string) $event?->command)->not->toContain('--fix')
        ->and($event?->expression)->toBe('0 2 * * *')
        ->and($event?->timezone)->toBe('Asia/Jakarta');
});
