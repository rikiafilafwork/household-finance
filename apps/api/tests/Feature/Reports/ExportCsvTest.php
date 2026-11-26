<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Reports\TransactionCsvExporter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/** @return TestResponse<Response> */
function exportCsv(User $user, string $query = 'from=2026-11-01&to=2026-11-30'): TestResponse
{
    return actingAs($user)->get("/api/reports/export.csv?{$query}");
}

/** @return list<list<string>> */
function csvRows(string $body, string $delimiter = ';'): array
{
    expect($body)->toStartWith("\u{FEFF}");
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, substr($body, 3));
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
        $rows[] = array_map('strval', $row);
    }

    return $rows;
}

it('streams the user’s transactions in date order with a BOM and Indonesian-friendly columns', function () {
    $user = User::factory()->create();
    $bca = Account::factory()->forUser($user)->opening(5_000_000)->create(['name' => 'BCA']);
    $wallet = Account::factory()->forUser($user)->cash()->create(['name' => 'Dompet']);
    $food = Category::factory()->forUser($user)->create(['name' => 'Makan & Minum']);
    $as = actingAs($user);
    $tx = fn (array $body) => (int) $as->postJson('/api/transactions', $body + ['account_id' => $bca->id, 'client_id' => (string) Str::uuid()])->assertCreated()->json('data.id');

    $kopi = $tx(['type' => 'expense', 'amount' => 25_000, 'occurred_on' => '2026-11-15', 'category_id' => $food->id, 'note' => 'Kopi; "susu" panas']);
    $gaji = $tx(['type' => 'income', 'amount' => 10_000_000, 'occurred_on' => '2026-11-01', 'note' => 'Gaji November']);
    $group = (string) $as->postJson('/api/transfers', [
        'from_account_id' => $bca->id, 'to_account_id' => $wallet->id, 'amount' => 300_000, 'occurred_on' => '2026-11-10', 'client_id' => (string) Str::uuid(),
    ])->assertCreated()->json('data.transfer_group_id');
    $tx(['type' => 'expense', 'amount' => 1_000, 'occurred_on' => '2026-10-31']);                 // di luar rentang
    $deleted = $tx(['type' => 'expense', 'amount' => 2_000, 'occurred_on' => '2026-11-20']);
    $as->deleteJson("/api/transactions/{$deleted}")->assertNoContent();
    Transaction::factory()->forAccount(Account::factory()->create())->expense(9_999)->create(['occurred_on' => '2026-11-15']); // user lain

    $response = exportCsv($user)->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertDownload('transaksi-2026-11-01_2026-11-30.csv');
    $rows = csvRows((string) $response->streamedContent());
    $transfer = Transaction::ownedBy($user)->where('transfer_group_id', $group)->orderBy('amount')->pluck('id')->all();

    expect($rows)->toBe([
        TransactionCsvExporter::HEADER,
        ['2026-11-01', 'BCA', 'bank', 'pemasukan', '', '10000000', 'Gaji November', '', (string) $gaji],
        ['2026-11-10', 'BCA', 'bank', 'transfer', '', '-300000', '', $group, (string) $transfer[0]],
        ['2026-11-10', 'Dompet', 'cash', 'transfer', '', '300000', '', $group, (string) $transfer[1]],
        ['2026-11-15', 'BCA', 'bank', 'pengeluaran', 'Makan & Minum', '-25000', 'Kopi; "susu" panas', '', (string) $kopi],
    ]);
});

it('uses the chosen delimiter', function (string $name, string $delimiter) {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->create(['name' => 'BCA, Utama']);
    Transaction::factory()->forAccount($account)->expense(25_000)->create(['occurred_on' => '2026-11-15', 'note' => "baris\nbaru"]);

    $rows = csvRows((string) exportCsv($user, "from=2026-11-01&to=2026-11-30&delimiter={$name}")->assertOk()->streamedContent(), $delimiter);

    expect($rows[1][1])->toBe('BCA, Utama')
        ->and($rows[1][6])->toBe("baris\nbaru")
        ->and(count($rows[1]))->toBe(count(TransactionCsvExporter::HEADER));
})->with([['semicolon', ';'], ['comma', ','], ['tab', "\t"]]);

it('neutralizes spreadsheet formulas in user text but keeps negative amounts numeric', function (string $note, string $expected) {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->create(['name' => '=HYPERLINK("http://x")']);
    Transaction::factory()->forAccount($account)->expense(25_000)->create(['occurred_on' => '2026-11-15', 'note' => $note]);

    $rows = csvRows((string) exportCsv($user)->streamedContent());

    expect($rows[1][1])->toBe('\'=HYPERLINK("http://x")')
        ->and($rows[1][5])->toBe('-25000')
        ->and($rows[1][6])->toBe($expected);
})->with([
    'equals' => ['=1+1', "'=1+1"],
    'plus' => ['+62 812', "'+62 812"],
    'minus' => ['-cmd', "'-cmd"],
    'at' => ['@SUM(A1)', "'@SUM(A1)"],
    'plain' => ['kopi', 'kopi'],
]);

it('pages through many rows on the same day without losing or repeating any', function () {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->create();
    $now = now();
    foreach (array_chunk(range(1, 2_345), 500) as $chunk) {
        Transaction::query()->insert(array_map(fn (int $i) => [
            'user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => -$i,
            'occurred_on' => $i <= 1_500 ? '2026-11-10' : '2026-11-11', 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    $rows = array_slice(csvRows((string) exportCsv($user)->streamedContent()), 1);

    expect($rows)->toHaveCount(2_345)
        ->and(array_unique(array_column($rows, 8)))->toHaveCount(2_345)
        ->and(array_sum(array_map('intval', array_column($rows, 5))))->toBe(-intdiv(2_345 * 2_346, 2))
        ->and(array_column($rows, 0))->toBe(array_merge(array_fill(0, 1_500, '2026-11-10'), array_fill(0, 845, '2026-11-11')));
});

it('keeps memory flat while exporting many rows', function () {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->create();
    $now = now();
    foreach (array_chunk(range(1, 30_000), 5_000) as $chunk) {
        Transaction::query()->insert(array_map(fn (int $i) => [
            'user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => -$i,
            'occurred_on' => '2026-11-'.str_pad((string) ($i % 28 + 1), 2, '0', STR_PAD_LEFT),
            'note' => str_repeat('catatan panjang ', 4), 'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    gc_collect_cycles();
    $before = memory_get_usage();
    $peak = 0;
    $rows = 0;
    foreach (app(TransactionCsvExporter::class)->lines($user, '2026-11-01', '2026-11-30') as $line) {
        $rows++;
        $peak = max($peak, memory_get_usage() - $before);
    }

    // 30 ribu baris (~3 MB CSV) tidak pernah ada di memori sekaligus; satu halaman 1.000 baris saja.
    expect($rows)->toBe(30_001)
        ->and($peak)->toBeLessThan(4 * 1024 * 1024);
});

it('defaults to the current month in the user timezone', function () {
    travelTo(new DateTimeImmutable('2026-11-30 16:30:00', new DateTimeZone('UTC')));   // 1 Des WIT
    $user = User::factory()->create(['timezone' => 'Asia/Jayapura']);

    exportCsv($user, '')->assertOk()->assertDownload('transaksi-2026-12-01_2026-12-01.csv');
});

it('validates the export parameters', function (string $query, string $field) {
    actingAs(User::factory()->create())->getJson("/api/reports/export.csv?{$query}")
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['from=30/11/2026', 'from'],
    ['from=2026-11-30&to=2026-11-01', 'to'],
    ['delimiter=pipe', 'delimiter'],
]);

it('rate limits exports per user', function () {
    $user = User::factory()->create();
    foreach (range(1, 10) as $_) {
        exportCsv($user)->assertOk();
    }

    exportCsv($user)->assertTooManyRequests();
});
