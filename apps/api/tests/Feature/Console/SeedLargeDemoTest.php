<?php

use App\Console\Commands\SeedLargeDemo;
use App\Models\Account;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/*
| #61 — demo:seed-large dengan angka kecil (struktur & invariant sama dengan 200 ribu + 1 juta).
*/

afterEach(fn () => CarbonImmutable::setTestNow());

function seedSmall(string $extra = ''): void
{
    artisan("demo:seed-large --transactions=3000 --noise=2000 --noise-users=4 --months=6 --seed=7 {$extra}")->assertSuccessful();
}

function demoUser(): User
{
    return User::query()->where('email', SeedLargeDemo::DEMO_EMAIL)->sole();
}

beforeEach(fn () => CarbonImmutable::setTestNow('2026-11-20 10:00:00 Asia/Jakarta'));

it('creates the demo account with the requested volume, a 6-month plan and noise users', function () {
    seedSmall();
    $demo = demoUser();

    expect(Transaction::ownedBy($demo)->count())->toBe(3_000)
        ->and(Account::ownedBy($demo)->count())->toBe(8)
        ->and(PlanItem::ownedBy($demo)->distinct()->pluck('month')->map(fn ($m) => substr((string) $m, 0, 7))->sort()->values()->all())
        ->toBe(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'])
        ->and(User::query()->where('email', 'like', '%@'.SeedLargeDemo::NOISE_DOMAIN)->count())->toBe(4)
        ->and(Transaction::query()->withoutGlobalScopes()->where('user_id', '<>', $demo->id)->count())->toBe(2_000);
});

it('keeps every balance consistent with its ledger', function () {
    seedSmall();

    assertLedgerBalanced();
    artisan('accounts:reconcile')->assertSuccessful();
});

it('produces realistic data the app accepts', function () {
    seedSmall();
    $demo = demoUser();
    $tx = Transaction::ownedBy($demo);

    // Semua tipe ada; transfer berpasangan dan bernet 0; tidak ada transaksi di masa depan.
    expect((clone $tx)->distinct()->pluck('type')->map(fn ($t) => $t->value)->sort()->values()->all())->toBe(['adjustment', 'expense', 'income', 'transfer'])
        ->and((int) (clone $tx)->where('type', 'transfer')->sum('amount'))->toBe(0)
        ->and((clone $tx)->where('type', 'transfer')->groupBy('transfer_group_id')->havingRaw('COUNT(*) <> 2')->count('transfer_group_id'))->toBe(0)
        ->and((clone $tx)->where('occurred_on', '>', '2026-11-20')->count())->toBe(0);

    // Checklist yang dicentang terhubung ke item bulan yang sama, satu transaksi per item.
    $linked = (clone $tx)->whereNotNull('plan_item_id')->get(['plan_item_id', 'occurred_on']);
    expect($linked)->not->toBeEmpty()
        ->and($linked->pluck('plan_item_id')->unique()->count())->toBe($linked->count());
    $months = PlanItem::ownedBy($demo)->whereKey($linked->pluck('plan_item_id'))->pluck('month', 'id');
    foreach ($linked as $row) {
        expect($row->occurred_on->format('Y-m'))->toBe(substr((string) $months[$row->plan_item_id], 0, 7));
    }

    // Laporan & dashboard bisa dihitung untuk data ini.
    actingAs($demo)->getJson('/api/dashboard?month=2026-10')->assertOk();
    actingAs($demo)->getJson('/api/reports/summary?month=2026-10')->assertOk()
        ->assertJsonPath('data.income.actual', fn (int $v) => $v > 0)
        ->assertJsonPath('data.expense.actual', fn (int $v) => $v > 0);
});

it('is deterministic for the same seed', function () {
    seedSmall();
    $first = DB::table('transactions')->where('user_id', demoUser()->id)->orderBy('id')->get(['account_id', 'type', 'amount', 'occurred_on'])->map(fn ($r) => (array) $r)->all();

    seedSmall('--fresh');
    $second = DB::table('transactions')->where('user_id', demoUser()->id)->orderBy('id')->get(['account_id', 'type', 'amount', 'occurred_on'])
        ->map(fn ($r) => (array) $r)->all();

    expect(array_map(fn ($r) => [$r['type'], $r['amount'], $r['occurred_on']], $second))
        ->toBe(array_map(fn ($r) => [$r['type'], $r['amount'], $r['occurred_on']], $first));
});

it('refuses to run twice without --fresh and replaces everything with it', function () {
    seedSmall();
    $other = User::factory()->create();
    Transaction::factory()->forAccount(Account::factory()->forUser($other)->create())->expense()->create();

    artisan('demo:seed-large --transactions=10 --noise=10 --noise-users=1 --months=1')->assertFailed();
    seedSmall('--fresh');

    expect(User::query()->where('email', SeedLargeDemo::DEMO_EMAIL)->count())->toBe(1)
        ->and(User::query()->where('email', 'like', '%@'.SeedLargeDemo::NOISE_DOMAIN)->count())->toBe(4)
        ->and(Transaction::query()->withoutGlobalScopes()->count())->toBe(3_000 + 2_000 + 1)      // user biasa tidak tersentuh
        ->and(Transaction::ownedBy($other)->count())->toBe(1);
});

it('refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    artisan('demo:seed-large --transactions=10 --noise=0 --months=1')->assertFailed();
    expect(User::query()->where('email', SeedLargeDemo::DEMO_EMAIL)->exists())->toBeFalse();
});
