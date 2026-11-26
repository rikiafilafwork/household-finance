<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\PlanFixture;

use function Pest\Laravel\actingAs;

/*
| #60 — isolasi ANGKA laporan & export. DataIsolationTest hanya memastikan nama/id milik user lain
| tidak muncul; laporan yang diam-diam ikut menjumlahkan nominal user lain lolos dari situ. Di sini:
| ambil semua laporan seorang user, beri user LAIN banyak data di bulan, kategori bernama sama, dan
| tipe akun yang sama, lalu ambil lagi: setiap byte harus identik.
*/

const REPORT_PATHS = [
    'summary?month=2026-11',
    'summary?month=2026-10',
    'by-category?from=2026-10&to=2026-11&kind=expense',
    'by-category?from=2026-11&to=2026-11&kind=income',
    'trend?months=3&end=2026-11',
    'cash-flow?from=2026-10-01&to=2026-11-30',
    'cash-vs-noncash?month=2026-11',
    'export.csv?from=2026-10-01&to=2026-11-30',
];

/** @return array<string, string> path → isi response */
function allReports(User $user): array
{
    $out = [];
    foreach (REPORT_PATHS as $path) {
        app('auth')->forgetGuards();
        /** @var TestResponse<Response> $response */
        $response = actingAs($user)->get("/api/reports/{$path}", ['Accept' => 'application/json'])->assertOk();
        $out[$path] = $response->baseResponse instanceof StreamedResponse ? (string) $response->streamedContent() : (string) $response->getContent();
    }

    return $out;
}

/** User lain dengan banyak data yang "mirip": kategori & akun bernama sama, bulan yang sama. */
function noisyNeighbour(): User
{
    $f = PlanFixture::architecture();          // rencana + transaksi November & Oktober, kategori bernama sama
    $wallet = Account::factory()->forUser($f->user)->cash()->opening(2_000_000)->create(['name' => 'Dompet']);
    $as = actingAs($f->user);
    foreach (['2026-10-05', '2026-11-03', '2026-11-17'] as $date) {
        $as->postJson('/api/transactions', [
            'account_id' => $wallet->id, 'type' => 'expense', 'amount' => 123_000, 'occurred_on' => $date,
            'category_id' => $f->cat['Makan']->id, 'client_id' => (string) Str::uuid(),
        ])->assertCreated();
    }
    $as->postJson("/api/accounts/{$wallet->id}/adjust", ['actual_balance' => 1_000_000, 'occurred_on' => '2026-11-28'])->assertCreated();

    return $f->user;
}

it('never lets another user’s money into any report or export', function () {
    $me = PlanFixture::architecture();
    $before = allReports($me->user);

    // Sanity: laporan saya memang berisi angka (kalau kosong, test ini tidak membuktikan apa-apa).
    expect($before['summary?month=2026-11'])->toContain('4075000')
        ->and(substr_count($before['export.csv?from=2026-10-01&to=2026-11-30'], "\n"))->toBeGreaterThan(10);

    $neighbour = noisyNeighbour();
    expect(Transaction::ownedBy($neighbour)->count())->toBeGreaterThan(15);

    foreach (allReports($me->user) as $path => $body) {
        expect($body)->toBe($before[$path], "Laporan {$path} berubah karena data user lain");
    }
});

it('gives a user with no data empty reports even when others have plenty', function () {
    noisyNeighbour();
    $empty = User::factory()->create();

    $reports = allReports($empty);
    $summary = json_decode($reports['summary?month=2026-11'], true)['data'];
    $flow = json_decode($reports['cash-flow?from=2026-10-01&to=2026-11-30'], true)['data'];

    expect([$summary['income']['actual'], $summary['expense']['actual'], $summary['net'], $summary['unrecorded']])->toBe([0, 0, 0, 0])
        ->and(json_decode($reports['by-category?from=2026-10&to=2026-11&kind=expense'], true)['data']['categories'])->toBe([])
        ->and(array_sum(array_column(json_decode($reports['trend?months=3&end=2026-11'], true)['data'], 'expense_actual')))->toBe(0)
        ->and([$flow['opening_balance'], $flow['closing_balance']])->toBe([0, 0])
        ->and(json_decode($reports['cash-vs-noncash?month=2026-11'], true)['data']['total'])->toBe(0)
        ->and(substr_count($reports['export.csv?from=2026-10-01&to=2026-11-30'], "\n"))->toBe(1);  // header saja
});
