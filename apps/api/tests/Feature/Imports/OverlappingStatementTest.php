<?php

use App\Models\Account;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
| Keputusan #13 / ADR-006: mutasi yang diunduh dua kali dengan rentang tanggal tumpang tindih
| (mis. 1–15 Nov lalu 10–30 Nov) tidak boleh menghasilkan transaksi dobel, dan transaksi identik
| yang sah (dua kopi Rp25.000 di hari yang sama) tetap terhitung semuanya.
*/

/** Mutasi lengkap November yang "sebenarnya" terjadi di rekening. */
const NOVEMBER = [
    ['01/11/2026', 'GAJI NOVEMBER', 10_000_000],
    ['03/11/2026', 'KOPI KENANGAN', -25_000],
    ['05/11/2026', 'LISTRIK PLN', -350_000],
    ['10/11/2026', 'KOPI KENANGAN', -25_000],
    ['10/11/2026', 'KOPI KENANGAN', -25_000],     // dua kopi identik di hari tumpang tindih
    ['12/11/2026', 'TRSF E-BANKING DB 1211/FTSCY/WS95051 BUDI', -500_000],
    ['15/11/2026', 'INDOMARET 1234', -87_500],
    ['20/11/2026', 'INTERNET INDIHOME', -385_000],
    ['28/11/2026', 'BUNGA', 1_234],
];

final class OverlapFixture
{
    public static self $current;

    public function __construct(public User $user, public Account $account) {}
}

function ofx(): OverlapFixture
{
    return OverlapFixture::$current;
}

beforeEach(function () {
    Storage::fake(Import::DISK);
    travelTo(new DateTimeImmutable('2026-11-30 20:00:00'));

    $user = User::factory()->create();
    OverlapFixture::$current = new OverlapFixture($user, Account::factory()->forUser($user)->opening(2_000_000)->create(['name' => 'BCA']));
});

/**
 * Baris NOVEMBER dengan tanggal dari..sampai (inklusif), sebagai CSV bank; `$reference` meniru
 * ekspor berbeda yang menulis nomor referensi lain di keterangan.
 */
function statementCsv(string $from, string $to, ?string $reference = null): string
{
    $lines = ['Tanggal;Keterangan;Jumlah'];
    foreach (NOVEMBER as [$date, $description, $amount]) {
        $iso = implode('-', array_reverse(explode('/', $date)));
        if ($iso < $from || $iso > $to) {
            continue;
        }
        $lines[] = implode(';', [$date, $reference === null ? $description : "{$description} {$reference}", number_format($amount, 0, ',', '.')]);
    }

    return implode("\n", $lines)."\n";
}

/** Upload + parse; id import. */
function stageStatement(string $csv): int
{
    return (int) actingAs(ofx()->user)->post('/api/imports', [
        'account_id' => ofx()->account->id,
        'format' => 'generic',
        'column_mapping' => json_encode(['date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Keterangan', 'amount' => 'Jumlah']),
        'file' => UploadedFile::fake()->createWithContent('mutasi.csv', $csv),
    ], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.status', 'ready')->json('data.id');
}

/** Commit semua baris yang bisa dipilih (new + possible_duplicate), tanpa kategori. */
function commitAll(int $importId): void
{
    $rows = Import::ownedBy(ofx()->user)->findOrFail($importId)->rows()
        ->whereIn('status', ['new', 'possible_duplicate'])->pluck('id');

    actingAs(ofx()->user)->postJson("/api/imports/{$importId}/commit", [
        'rows' => $rows->map(fn (int $id) => ['id' => $id, 'category_id' => null])->all(),
    ])->assertOk();
}

/** @return array<string, int> status → jumlah baris */
function statusCounts(int $importId): array
{
    $counts = Import::ownedBy(ofx()->user)->findOrFail($importId)->rows()
        ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
    ksort($counts);

    return $counts;
}

/** Ledger akun harus persis sama dengan NOVEMBER (urutan tanggal), tanpa dobel. */
function assertLedgerIsNovember(): void
{
    $ledger = Transaction::ownedBy(ofx()->user)->orderBy('occurred_on')->orderBy('id')->get()
        ->map(fn (Transaction $t) => [$t->occurred_on->format('d/m/Y'), $t->amount])->all();

    expect($ledger)->toBe(array_map(fn (array $row) => [$row[0], $row[2]], NOVEMBER))
        ->and(ofx()->account->refresh()->balance)->toBe(2_000_000 + array_sum(array_column(NOVEMBER, 2)));

    assertLedgerBalanced();
    expect(Artisan::call('accounts:reconcile'))->toBe(0);
}

it('imports only the new part of an overlapping statement', function () {
    $first = stageStatement(statementCsv('2026-11-01', '2026-11-15'));
    commitAll($first);

    $second = stageStatement(statementCsv('2026-11-10', '2026-11-30'));

    // 10–15 Nov sudah masuk (termasuk KEDUA kopi tanggal 10), 20 & 28 Nov baru.
    expect(statusCounts($second))->toBe(['duplicate' => 4, 'new' => 2]);

    commitAll($second);
    assertLedgerIsNovember();
});

it('does not double anything when both overlapping statements are parsed before either is committed', function () {
    $first = stageStatement(statementCsv('2026-11-01', '2026-11-15'));
    $second = stageStatement(statementCsv('2026-11-10', '2026-11-30'));
    expect(statusCounts($second))->toBe(['new' => 6]);   // belum ada yang di-commit

    commitAll($second);
    commitAll($first);

    // Overlap yang ternyata sudah masuk lewat file lain ditandai duplicate saat commit.
    expect(statusCounts($first))->toBe(['committed' => 3, 'duplicate' => 4]);
    assertLedgerIsNovember();
});

it('treats the full month after two halves as fully duplicate', function () {
    commitAll(stageStatement(statementCsv('2026-11-01', '2026-11-12')));
    commitAll(stageStatement(statementCsv('2026-11-13', '2026-11-30')));

    $month = stageStatement(statementCsv('2026-11-01', '2026-11-30'));

    expect(statusCounts($month))->toBe(['duplicate' => count(NOVEMBER)]);
    assertLedgerIsNovember();
});

it('matches overlapping rows even when the export writes different reference numbers', function () {
    commitAll(stageStatement(statementCsv('2026-11-01', '2026-11-15', reference: '0001/FT/WS11111')));

    $second = stageStatement(statementCsv('2026-11-10', '2026-11-30', reference: '0002/FT/WS22222'));

    expect(statusCounts($second))->toBe(['duplicate' => 4, 'new' => 2]);
});

it('keeps both identical transactions when a later statement adds the second one of that day', function () {
    // File pertama diunduh siang hari: baru ada kopi pertama tanggal 10.
    commitAll(stageStatement(implode("\n", array_slice(explode("\n", statementCsv('2026-11-01', '2026-11-10')), 0, -2))."\n"));
    expect(Transaction::ownedBy(ofx()->user)->where('occurred_on', '2026-11-10')->count())->toBe(1);

    // File kedua memuat hari itu utuh: kopi pertama duplicate (occurrence 0), kopi kedua baru (occurrence 1).
    $second = stageStatement(statementCsv('2026-11-10', '2026-11-30'));
    expect(statusCounts($second))->toBe(['duplicate' => 1, 'new' => 5]);

    commitAll($second);
    assertLedgerIsNovember();
});
