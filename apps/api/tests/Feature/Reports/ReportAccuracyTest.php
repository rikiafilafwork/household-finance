<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\PlanFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
| #58 — angka laporan terhadap fixture yang dihitung TANGAN (PlanFixture::architecture):
|
|  November 2026 (BCA, saldo awal 20.000.000)
|    pemasukan: rencana 9.500.000, aktual 8.000.000 (gaji, dicentang 25 Nov)
|    pengeluaran: rencana 5.100.000 (asuransi di-skip), aktual 4.075.000:
|      cicilan 1.200.000 (5 Nov), internet 350.000 + pulsa 165.000 (10 Nov),
|      makan 3×450.000 + transport 510.000 + hiburan 320.000 + kesehatan 180.000 (15 Nov)
|    derau: makan 99.000 tgl 31 Okt, transaksi terhapus 77.000, transfer 300.000 BCA → dompet
|           (12 Nov), sesuaikan saldo dompet 300.000 → 250.000 (20 Nov), transaksi user lain.
|  Oktober 2026: hanya makan 99.000 (di luar rencana).
*/

/** @return array<string, mixed> */
function reports(PlanFixture $f, string $path): array
{
    return actingAs($f->user)->getJson("/api/reports/{$path}")->assertOk()->json('data');
}

/**
 * Daftar baris laporan (mis. `days`, `categories`) sebagai list bertipe.
 *
 * @param  array<string, mixed>  $report
 * @return list<array<string, mixed>>
 */
function rowsOf(array $report, string $key): array
{
    /** @var list<array<string, mixed>> */
    return $report[$key];
}

it('summarizes a month with plan vs actual, last month and unrecorded cash', function () {
    $f = PlanFixture::architecture();

    $summary = reports($f, 'summary?month=2026-11');

    expect($summary['income'])->toBe(['planned' => 9_500_000, 'actual' => 8_000_000, 'unplanned' => 0, 'unpaid' => 0, 'envelope_remaining' => 1_500_000])
        ->and($summary['expense'])->toBe(['planned' => 5_100_000, 'actual' => 4_075_000, 'unplanned' => 180_000, 'unpaid' => 500_000, 'envelope_remaining' => 740_000])
        ->and($summary['net'])->toBe(3_925_000)
        ->and($summary['remaining_budget'])->toBe(1_025_000)
        ->and($summary['unrecorded'])->toBe(-50_000)
        ->and($summary['previous'])->toBe([
            'month' => '2026-10',
            'income' => 0,
            'expense' => 99_000,
            'net' => -99_000,
            'change' => ['income' => 8_000_000, 'expense' => 3_976_000, 'net' => 4_024_000],
        ]);
});

it('equals the dashboard for the same month', function () {
    $f = PlanFixture::architecture();

    $summary = reports($f, 'summary?month=2026-11');
    $dashboard = actingAs($f->user)->getJson('/api/dashboard?month=2026-11')->assertOk()->json('data');

    expect([$summary['income'], $summary['expense'], $summary['remaining_budget']])
        ->toBe([$dashboard['income'], $dashboard['expense'], $dashboard['remaining_budget']]);
});

it('breaks a month down by category with plan and share of the total', function () {
    $f = PlanFixture::architecture();

    $report = reports($f, 'by-category?from=2026-11&to=2026-11&kind=expense');

    expect($report['total_planned'])->toBe(5_100_000)
        ->and($report['total_actual'])->toBe(4_075_000)
        ->and(array_map(fn (array $r) => [$r['name'], $r['planned'], $r['actual'], $r['percent']], $report['categories']))->toBe([
            ['Makan', 2_000_000, 1_350_000, 33],
            ['Cicilan', 1_200_000, 1_200_000, 29],
            ['Tagihan', 1_000_000, 515_000, 12],
            ['Transport', 600_000, 510_000, 12],
            ['Hiburan', 300_000, 320_000, 7],
            ['Kesehatan', 0, 180_000, 4],
        ]);
});

it('adds months up across a range and reports income separately', function () {
    $f = PlanFixture::architecture();

    $expense = reports($f, 'by-category?from=2026-10&to=2026-11');
    $makan = collect(rowsOf($expense, 'categories'))->firstWhere('name', 'Makan');
    $income = reports($f, 'by-category?from=2026-11&to=2026-11&kind=income');

    expect($expense['kind'])->toBe('expense')
        ->and($expense['total_actual'])->toBe(4_174_000)
        ->and([$makan['planned'], $makan['actual']])->toBe([2_000_000, 1_449_000])
        ->and(array_map(fn (array $r) => [$r['name'], $r['planned'], $r['actual']], $income['categories']))->toBe([
            ['Gaji', 8_000_000, 8_000_000],
            ['Freelance', 1_500_000, 0],
        ]);
});

it('shows the monthly trend with plans, matching the dashboard trend', function () {
    $f = PlanFixture::architecture();

    $trend = reports($f, 'trend?months=2&end=2026-11');

    expect($trend)->toBe([
        ['month' => '2026-10', 'income_planned' => 0, 'income_actual' => 0, 'expense_planned' => 0, 'expense_actual' => 99_000, 'net' => -99_000],
        ['month' => '2026-11', 'income_planned' => 9_500_000, 'income_actual' => 8_000_000, 'expense_planned' => 5_100_000, 'expense_actual' => 4_075_000, 'net' => 3_925_000],
    ]);

    $dashboardTrend = actingAs($f->user)->getJson('/api/dashboard?month=2026-11')->json('data.trend');
    expect(array_map(fn (array $m) => [$m['month'], $m['income_actual'], $m['expense_actual'], $m['expense_planned']], reports($f, 'trend?months=6&end=2026-11')))
        ->toBe(array_map(fn (array $m) => [$m['month'], $m['income_actual'], $m['expense_actual'], $m['expense_planned']], $dashboardTrend));
});

it('runs the daily cash flow from the balance before the range to the account balances', function () {
    $f = PlanFixture::architecture();

    $flow = reports($f, 'cash-flow?from=2026-11-01&to=2026-11-30');
    $byDate = collect(rowsOf($flow, 'days'))->keyBy('date');

    expect($flow['opening_balance'])->toBe(20_000_000 - 99_000)
        ->and(count($flow['days']))->toBe(30)
        ->and(collect(rowsOf($flow, 'days'))->filter(fn (array $d) => $d['net'] !== 0)->map(fn (array $d) => [$d['date'], $d['net']])->values()->all())->toBe([
            ['2026-11-05', -1_200_000],
            ['2026-11-10', -515_000],
            ['2026-11-15', -2_360_000],
            ['2026-11-20', -50_000],      // sesuaikan saldo dompet
            ['2026-11-25', 8_000_000],
        ])
        ->and($byDate['2026-11-12']['net'])->toBe(0)            // transfer antar akun sendiri saling meniadakan
        ->and($byDate['2026-11-04']['balance'])->toBe(19_901_000)
        ->and($byDate['2026-11-05']['balance'])->toBe(18_701_000)
        ->and($byDate['2026-11-30']['balance'])->toBe(23_776_000)
        ->and($flow['closing_balance'])->toBe((int) Account::ownedBy($f->user)->sum('balance'));
});

it('limits the cash flow to one account', function () {
    $f = PlanFixture::architecture();

    $flow = reports($f, "cash-flow?from=2026-11-01&to=2026-11-30&account={$f->bca->id}");

    expect($flow['account_id'])->toBe($f->bca->id)
        ->and(collect(rowsOf($flow, 'days'))->firstWhere('date', '2026-11-12')['net'])->toBe(-300_000)
        ->and($flow['closing_balance'])->toBe($f->bca->refresh()->balance);
});

it('splits spending by account type with the same total as the summary', function () {
    $f = PlanFixture::architecture();

    $report = reports($f, 'cash-vs-noncash?month=2026-11');

    expect($report['total'])->toBe(4_075_000)
        ->and($report['types'])->toBe([
            ['type' => 'bank', 'expense' => 4_075_000, 'percent' => 100, 'unrecorded' => 0],
            ['type' => 'ewallet', 'expense' => 0, 'percent' => 0, 'unrecorded' => 0],
            ['type' => 'cash', 'expense' => 0, 'percent' => 0, 'unrecorded' => -50_000],
        ]);
});

it('attributes a bill paid early to its plan month, like the plan page', function () {
    $f = new PlanFixture;
    $wallet = Account::factory()->forUser($f->user)->cash()->opening(1_000_000)->create(['name' => 'Dompet']);
    $f->checklist('Listrik', 'Tagihan', 500_000);                                 // item November
    actingAs($f->user)->postJson("/api/plan-items/{$f->item['Listrik']->id}/check", [
        'client_id' => (string) Str::uuid(), 'amount' => 480_000, 'occurred_on' => '2026-10-30', 'account_id' => $wallet->id,
    ])->assertCreated();
    $f->spend('Makan', 20_000, '2026-11-02');

    $november = reports($f, 'cash-vs-noncash?month=2026-11');
    $october = reports($f, 'cash-vs-noncash?month=2026-10');

    expect(array_column($november['types'], 'expense', 'type'))->toBe(['bank' => 20_000, 'ewallet' => 0, 'cash' => 480_000])
        ->and($november['total'])->toBe(reports($f, 'summary?month=2026-11')['expense']['actual'])
        ->and($october['total'])->toBe(0)
        ->and($november['types'][2]['percent'])->toBe(96);
});

it('defaults to the current month in the user timezone', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Jayapura']);
    travelTo(new DateTimeImmutable('2026-11-30 16:30:00', new DateTimeZone('UTC')));   // 1 Des 01.30 WIT

    actingAs($user)->getJson('/api/reports/summary')->assertOk()->assertJsonPath('data.month', '2026-12');
    actingAs($user)->getJson('/api/reports/trend')->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('data.5.month', '2026-12');
    actingAs($user)->getJson('/api/reports/cash-flow')->assertOk()->assertJsonPath('data.from', '2026-12-01')->assertJsonPath('data.to', '2026-12-01');
});

it('validates report parameters', function (string $path, string $field) {
    $user = User::factory()->create();

    actingAs($user)->getJson("/api/reports/{$path}")->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['summary?month=2026-13', 'month'],
    ['summary?month=2026-1', 'month'],
    ['by-category?kind=transfer', 'kind'],
    ['by-category?from=2026-11&to=2026-10', 'to'],
    ['by-category?from=2024-01&to=2026-11', 'from'],
    ['trend?months=0', 'months'],
    ['trend?months=25', 'months'],
    ['trend?end=11-2026', 'end'],
    ['cash-flow?from=2026-11-30&to=2026-11-01', 'to'],
    ['cash-flow?from=2025-01-01&to=2026-11-30', 'to'],
    ['cash-flow?from=30/11/2026', 'from'],
    ['cash-vs-noncash?month=nov', 'month'],
]);

it('treats another user’s account in the cash flow like one that does not exist', function () {
    $user = User::factory()->create();
    $foreign = Account::factory()->create();

    actingAs($user)->getJson("/api/reports/cash-flow?account={$foreign->id}")->assertNotFound();
    actingAs($user)->getJson('/api/reports/cash-flow?account=999999999')->assertNotFound();
});
