<?php

use App\Models\Account;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Plan\PlanMonth;
use App\Queries\DashboardQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\PlanFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
| Keputusan #11: GET /api/dashboard memakai PlanComparisonQuery yang sama dengan halaman Rencana.
| Fixture angka Architecture.md #11 (PlanFixture::architecture, bulan 2026-11).
*/

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * @return array<string, mixed>
 */
function dashboard(PlanFixture $f, string $month = '2026-11', string $nowUtc = '2026-11-24 03:00'): array
{
    return app(DashboardQuery::class)->for($f->user, PlanMonth::parse($month), CarbonImmutable::parse($nowUtc, 'UTC'));
}

it('reproduces the hand-computed fixture from Architecture.md #11', function () {
    $d = dashboard(PlanFixture::architecture());

    expect($d['income'])->toMatchArray(['planned' => 9_500_000, 'actual' => 8_000_000])
        ->and($d['expense'])->toMatchArray(['planned' => 5_100_000, 'actual' => 4_075_000, 'unplanned' => 180_000, 'unpaid' => 500_000])
        ->and($d['remaining_budget'])->toBe(1_025_000)
        ->and($d['still_to_spend'])->toBe(1_240_000)
        ->and($d['reconcile_ok'])->toBeTrue();
});

it('returns exactly the numbers of GET /api/plan for the same month', function () {
    $f = PlanFixture::architecture();
    CarbonImmutable::setTestNow('2026-11-24 03:00:00 UTC');

    $plan = actingAs($f->user)->getJson('/api/plan/2026-11')->assertOk()->json('data');
    $dash = actingAs($f->user)->getJson('/api/dashboard?month=2026-11')->assertOk()->json('data');

    expect($dash['income'])->toBe($plan['totals']['income'])
        ->and($dash['expense'])->toBe($plan['totals']['expense'])
        ->and($dash['remaining_budget'])->toBe($plan['totals']['remaining_budget'])
        ->and($dash['still_to_spend'])->toBe($plan['totals']['still_to_spend'])
        ->and(array_map(fn ($r) => [$r['name'], $r['planned'], $r['actual']], $dash['categories']))
        ->toBe(array_values(array_map(fn ($r) => [$r['name'], $r['planned'], $r['actual']], array_filter($plan['categories'], fn ($r) => $r['type'] === 'expense'))));
});

it('breaks expenses down by category so that the rows add up to the total, with colors', function () {
    $d = dashboard(PlanFixture::architecture());
    /** @var list<array{id: int|null, name: string, color: string|null, planned: int, actual: int}> $rows */
    $rows = $d['categories'];
    $cats = collect($rows);

    expect(array_sum(array_column($rows, 'actual')))->toBe(4_075_000)
        ->and(array_sum(array_column($rows, 'planned')))->toBe(5_100_000)
        ->and($cats->firstWhere('name', 'Makan'))->toMatchArray(['planned' => 2_000_000, 'actual' => 1_350_000])
        ->and($cats->firstWhere('name', 'Kesehatan'))->toMatchArray(['planned' => 0, 'actual' => 180_000])
        ->and($cats->every(fn (array $r) => is_string($r['color'])))->toBeTrue();
});

it('lists income sources with their status and warns about envelopes from 80%, fullest first', function () {
    $d = dashboard(PlanFixture::architecture());

    expect(array_map(fn ($r) => [$r['name'], $r['planned'], $r['actual'], $r['done']], $d['income_sources']))->toBe([
        ['Gaji', 8_000_000, 8_000_000, true],
        ['Freelance', 1_500_000, 0, false],
    ])
        ->and(array_map(fn ($r) => [$r['name'], $r['percent']], $d['envelopes_warning']))->toBe([['Hiburan', 106], ['Transport', 85]]);
});

it('lists checklist items due within 7 days or overdue, in the user’s timezone', function () {
    $f = PlanFixture::architecture();
    $f->item['Listrik']->update(['due_on' => '2026-11-20']);          // lewat
    foreach (['Air' => '2026-11-30', 'Iuran' => '2026-11-28'] as $name => $due) {
        PlanItem::factory()->forUser($f->user)->create(['name' => $name, 'category_id' => $f->cat['Tagihan']->id, 'due_on' => $due]);
    }
    $f->item['Asuransi tahunan']->update(['due_on' => '2026-11-22']);  // di-skip → tidak muncul
    $f->item['Cicilan motor']->update(['due_on' => '2026-11-05']);     // sudah dicentang → tidak muncul

    // 23 Nov 18.00 UTC = 24 Nov 01.00 WIB → batas 1 Des.
    $d = dashboard($f, nowUtc: '2026-11-23 18:00');

    expect(array_map(fn ($r) => [$r['name'], $r['due_on'], $r['overdue']], $d['due_items']))->toBe([
        ['Listrik', '2026-11-20', true],
        ['Iuran', '2026-11-28', false],
        ['Air', '2026-11-30', false],
    ]);

    // 16 Nov 03.00 UTC: batas 23 Nov → Iuran/Air belum masuk, Listrik belum lewat.
    expect(array_map(fn ($r) => [$r['name'], $r['overdue']], dashboard($f, nowUtc: '2026-11-16 03:00')['due_items']))->toBe([['Listrik', false]]);
});

it('shows today’s balances per type of active accounts, and free money only for the current month', function () {
    $f = PlanFixture::architecture();
    Account::factory()->forUser($f->user)->ewallet()->opening(250_000)->create();
    Account::factory()->forUser($f->user)->opening(999_000)->archived()->create();

    $d = dashboard($f);
    $bca = (int) $f->bca->fresh()?->balance;

    // Dompet tunai fixture (transfer 300.000 lalu disesuaikan ke 250.000).
    expect($d['balances'])->toBe(['bank' => $bca, 'ewallet' => 250_000, 'cash' => 250_000, 'total' => $bca + 500_000])
        ->and($d['is_current_month'])->toBeTrue()
        ->and($d['free_money'])->toBe($bca + 500_000 - 1_240_000)
        ->and(dashboard($f, '2026-10')['free_money'])->toBeNull()
        ->and(dashboard($f, '2026-10')['is_current_month'])->toBeFalse();
});

it('builds a six-month trend that matches the plan of each month', function () {
    $f = PlanFixture::architecture();

    $trend = dashboard($f)['trend'];

    expect(array_column($trend, 'month'))->toBe(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'])
        ->and(end($trend))->toBe(['month' => '2026-11', 'income_actual' => 8_000_000, 'expense_actual' => 4_075_000, 'expense_planned' => 5_100_000])
        ->and($trend[4])->toBe(['month' => '2026-10', 'income_actual' => 0, 'expense_actual' => 99_000, 'expense_planned' => 0]); // makan 31 Okt
});

it('shows the five latest transactions', function () {
    $f = PlanFixture::architecture();

    $recent = dashboard($f)['recent'];
    $expected = Transaction::ownedBy($f->user)->orderByDesc('occurred_on')->orderByDesc('id')->limit(5)->pluck('id')->all();

    expect($recent)->toHaveCount(5)
        ->and(array_column($recent, 'id'))->toBe($expected)
        ->and($recent[0])->toHaveKeys(['type', 'account_id', 'category_id', 'amount', 'occurred_on', 'note', 'plan_item_id']);
});

it('flags a balance that drifted from its transactions', function () {
    $f = PlanFixture::architecture();
    Account::query()->withoutGlobalScopes()->whereKey($f->bca->id)->increment('balance', 1);

    expect(dashboard($f)['reconcile_ok'])->toBeFalse();
});

it('does not fail on a month without a plan or transactions', function () {
    $f = new PlanFixture;

    $d = dashboard($f, '2027-01', '2027-01-10 03:00');

    expect($d['income'])->toMatchArray(['planned' => 0, 'actual' => 0])
        ->and($d['expense'])->toMatchArray(['planned' => 0, 'actual' => 0, 'unpaid' => 0])
        ->and($d['remaining_budget'])->toBe(0)
        ->and($d['categories'])->toBe([])
        ->and($d['income_sources'])->toBe([])
        ->and($d['due_items'])->toBe([])
        ->and($d['envelopes_warning'])->toBe([])
        ->and($d['recent'])->toBe([])
        ->and($d['trend'])->toHaveCount(6)
        ->and($d['free_money'])->toBe(20_000_000)
        ->and($d['reconcile_ok'])->toBeTrue();
});

describe('GET /api/dashboard', function () {
    it('defaults to the current month in the user’s timezone', function () {
        $f = new PlanFixture;
        $f->user->update(['timezone' => 'Asia/Jakarta']);
        CarbonImmutable::setTestNow('2026-11-30 18:00:00 UTC'); // 1 Des 01.00 WIB

        actingAs($f->user)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.month', '2026-12')
            ->assertJsonPath('data.is_current_month', true);
    });

    it('rejects a malformed month', function () {
        $f = new PlanFixture;
        actingAs($f->user)->getJson('/api/dashboard?month=2026-13')->assertUnprocessable()->assertJsonValidationErrors(['month']);
    });

    it('requires login', function () {
        getJson('/api/dashboard')->assertUnauthorized();
    });

    it('needs only a handful of queries regardless of how much data there is', function () {
        $f = PlanFixture::architecture();
        DB::enableQueryLog();

        actingAs($f->user)->getJson('/api/dashboard?month=2026-11')->assertOk();

        // bulan ini (~4) + 5 bulan tren (~4 tiap bulan) + saldo + kategori + transaksi terbaru + reconcile + auth
        expect(count(DB::getQueryLog()))->toBeLessThan(40);
    });
});

it('serves another user nothing of this user’s data', function () {
    $f = PlanFixture::architecture();
    $other = new PlanFixture;

    $d = dashboard($other);

    expect($d['expense']['actual'])->toBe(0)
        ->and($d['recent'])->toBe([])
        ->and($d['balances']['total'])->toBe(20_000_000)
        ->and((string) json_encode($d))->not->toContain('Cicilan motor');
    expect($f->user->id)->not->toBe($other->user->id);
});
