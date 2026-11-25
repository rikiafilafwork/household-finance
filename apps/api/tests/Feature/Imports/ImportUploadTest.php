<?php

use App\Actions\Imports\StageImportRows;
use App\Enums\ImportStatus;
use App\Imports\FingerprintCalculator;
use App\Jobs\ParseImport;
use App\Models\Account;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

const MAPPING = [
    'date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Keterangan',
    'debit' => 'Debet', 'credit' => 'Kredit',
];

const STATEMENT = <<<'CSV'
    Tanggal;Keterangan;Debet;Kredit
    01/11/2026;GAJI NOVEMBER;;10.000.000,00
    03/11/2026;KOPI KENANGAN;25.000,00;
    03/11/2026;KOPI KENANGAN;25.000,00;
    05/11/2026;LISTRIK PLN;350.000,00;
    PEND;TARIK TUNAI;100.000,00;
    CSV;

final class ImportFixture
{
    public static self $current;

    public function __construct(public User $user, public Account $account) {}
}

/** User + akun bank milik test yang sedang berjalan (dibuat ulang di beforeEach). */
function fx(): ImportFixture
{
    return ImportFixture::$current;
}

beforeEach(function () {
    Storage::fake(Import::DISK);
    travelTo(new DateTimeImmutable('2026-11-25 10:00:00'));

    $user = User::factory()->create();
    ImportFixture::$current = new ImportFixture($user, Account::factory()->forUser($user)->opening(1_000_000)->create(['name' => 'BCA']));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function upload(array $overrides = [], string $contents = STATEMENT, string $filename = 'mutasi-november.csv'): TestResponse
{
    return actingAs(fx()->user)->post('/api/imports', array_merge([
        'account_id' => fx()->account->id,
        'format' => 'generic',
        'column_mapping' => json_encode(MAPPING),
        'file' => UploadedFile::fake()->createWithContent($filename, $contents),
    ], $overrides), ['Accept' => 'application/json']);
}

/** @return list<array{int, string|null, int|null, string}> */
function rowSummary(int $importId): array
{
    return array_map(
        fn (array $r) => [$r['row_number'], $r['occurred_on'], $r['amount'], $r['status']],
        actingAs(fx()->user)->getJson("/api/imports/{$importId}/rows")->assertOk()->json('data'),
    );
}

it('uploads a statement to the private disk and parses it into staging rows', function () {
    $response = upload()->assertAccepted()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.format', 'generic')
        ->assertJsonPath('data.original_filename', 'mutasi-november.csv')
        ->assertJsonPath('data.headers', ['Tanggal', 'Keterangan', 'Debet', 'Kredit'])
        ->assertJsonPath('data.counts', ['total' => 5, 'new' => 4, 'duplicate' => 0, 'possible_duplicate' => 0, 'invalid' => 1])
        ->assertJsonMissingPath('data.file_path');

    $import = Import::query()->findOrFail($response->json('data.id'));
    Storage::disk(Import::DISK)->assertExists((string) $import->file_path);
    expect($import->file_path)->toStartWith('imports/'.fx()->user->id.'/');

    expect(rowSummary($import->id))->toBe([
        [2, '2026-11-01', 10_000_000, 'new'],
        [3, '2026-11-03', -25_000, 'new'],
        [4, '2026-11-03', -25_000, 'new'],
        [5, '2026-11-05', -350_000, 'new'],
        [6, null, null, 'invalid'],
    ]);

    $invalid = actingAs(fx()->user)->getJson("/api/imports/{$import->id}/rows?status=invalid")->assertOk();
    expect($invalid->json('data'))->toHaveCount(1)
        ->and($invalid->json('data.0.error'))->toContain('PEND')
        ->and($invalid->json('data.0.raw'))->toBe(['PEND', 'TARIK TUNAI', '100.000,00', '']);

    // Dua kopi identik di hari yang sama tetap dua baris berbeda.
    expect($import->rows()->pluck('fingerprint')->filter()->unique())->toHaveCount(4);
});

it('does not touch balances or transactions while staging', function () {
    upload()->assertAccepted();

    expect(Transaction::query()->count())->toBe(0)
        ->and(fx()->account->refresh()->balance)->toBe(1_000_000);
});

it('marks rows already imported into this account as duplicates, including deleted ones', function () {
    $fingerprints = FingerprintCalculator::forLines(fx()->account->id, [
        ['occurred_on' => '2026-11-01', 'amount' => 10_000_000, 'description' => 'GAJI NOVEMBER'],
        ['occurred_on' => '2026-11-03', 'amount' => -25_000, 'description' => 'KOPI KENANGAN'],
    ]);
    Transaction::factory()->forAccount(fx()->account)->income(10_000_000)
        ->create(['occurred_on' => '2026-11-01', 'import_hash' => $fingerprints[0]]);
    Transaction::factory()->forAccount(fx()->account)->expense(25_000)
        ->create(['occurred_on' => '2026-11-03', 'import_hash' => $fingerprints[1], 'deleted_at' => now()]);

    $id = upload()->assertAccepted()
        ->assertJsonPath('data.counts.duplicate', 2)
        ->assertJsonPath('data.counts.new', 2)
        ->json('data.id');

    expect(rowSummary($id))->toBe([
        [2, '2026-11-01', 10_000_000, 'duplicate'],
        [3, '2026-11-03', -25_000, 'duplicate'],
        [4, '2026-11-03', -25_000, 'new'],          // kopi kedua belum pernah diimpor
        [5, '2026-11-05', -350_000, 'new'],
        [6, null, null, 'invalid'],
    ]);
});

it('ignores import hashes from another account', function () {
    $other = Account::factory()->forUser(fx()->user)->create(['name' => 'Mandiri']);
    $fingerprint = FingerprintCalculator::fingerprint(fx()->account->id, '2026-11-01', 10_000_000, 'GAJI NOVEMBER', 0);
    Transaction::factory()->forAccount($other)->income(10_000_000)->create(['occurred_on' => '2026-11-01', 'import_hash' => $fingerprint]);

    upload()->assertAccepted()->assertJsonPath('data.counts.duplicate', 0);
});

it('flags manually recorded transactions within a day as possible duplicates, one CSV row each', function () {
    $account = fx()->account;
    // Satu kopi dicatat manual sehari setelahnya; listrik dicatat dua hari setelahnya (di luar ±1).
    Transaction::factory()->forAccount($account)->expense(25_000)->create(['occurred_on' => '2026-11-04']);
    Transaction::factory()->forAccount($account)->expense(350_000)->create(['occurred_on' => '2026-11-07']);
    // Bukan kandidat: amount sama tapi akun lain, transaksi terhapus, dan penyesuaian saldo.
    Transaction::factory()->forAccount(Account::factory()->forUser(fx()->user)->create())->income(10_000_000)->create(['occurred_on' => '2026-11-01']);
    Transaction::factory()->forAccount($account)->income(10_000_000)->create(['occurred_on' => '2026-11-01', 'deleted_at' => now()]);
    Transaction::factory()->forAccount($account)->adjustment(10_000_000)->create(['occurred_on' => '2026-11-01']);

    $id = upload()->assertAccepted()->assertJsonPath('data.counts.possible_duplicate', 1)->json('data.id');

    expect(rowSummary($id))->toBe([
        [2, '2026-11-01', 10_000_000, 'new'],
        [3, '2026-11-03', -25_000, 'possible_duplicate'],
        [4, '2026-11-03', -25_000, 'new'],
        [5, '2026-11-05', -350_000, 'new'],
        [6, null, null, 'invalid'],
    ]);
});

it('resolves dates without a year from the upload date in the user timezone', function () {
    // 31 Des 2026 23.30 WIB = 16.30 UTC; tanggal upload lokal masih 2026 → "31/12" = 2026-12-31.
    travelTo(new DateTimeImmutable('2026-12-31 16:30:00', new DateTimeZone('UTC')));

    $id = upload([
        'column_mapping' => json_encode(['date' => 0, 'date_format' => 'd/m', 'description' => 1, 'amount' => 2, 'has_header' => false]),
    ], "31/12,KOPI,-25.000\n01/01,TEH,-5.000\n")->assertAccepted()->json('data.id');

    expect(rowSummary($id))->toBe([
        [1, '2026-12-31', -25_000, 'new'],
        [2, '2026-01-01', -5_000, 'new'],
    ]);
});

it('fails the import with a readable error when the file does not match the mapping', function () {
    upload([], "Tanggal;Ket;Jumlah\n03/11/2026;KOPI;25.000\n")->assertAccepted()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.counts.total', 0)
        ->assertJsonPath('data.error', fn (string $error) => str_contains($error, 'Kolom "Keterangan" tidak ada di header'));
});

it('only imports into active bank accounts', function (Closure $account, int $status, ?string $error) {
    $response = upload(['account_id' => $account(fx()->user)->id])->assertStatus($status);
    if ($error !== null) {
        $response->assertJsonValidationErrors(['account_id' => $error]);
    }
    expect(Import::query()->count())->toBe(0);
})->with([
    'e-wallet' => [fn (User $u) => Account::factory()->forUser($u)->ewallet()->create(), 422, 'hanya untuk akun rekening'],
    'cash' => [fn (User $u) => Account::factory()->forUser($u)->cash()->create(), 422, 'hanya untuk akun rekening'],
    'archived bank' => [fn (User $u) => Account::factory()->forUser($u)->archived()->create(), 422, 'diarsip'],
    'another user’s bank' => [fn (User $u) => Account::factory()->create(), 404, null],
]);

it('validates the upload', function (array $overrides, string $field) {
    upload($overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Import::query()->count())->toBe(0);
})->with([
    'missing file' => [['file' => null], 'file'],
    'wrong extension' => [['file' => UploadedFile::fake()->createWithContent('mutasi.pdf', '%PDF')], 'file'],
    'too large' => [['file' => UploadedFile::fake()->create('mutasi.csv', 5_121)], 'file'],
    'unknown format' => [['format' => 'bca-pdf'], 'format'],
    'mapping not json' => [['column_mapping' => '{date:'], 'column_mapping'],
    'mapping is a list' => [['column_mapping' => '[1,2]'], 'column_mapping'],
    'invalid mapping' => [['column_mapping' => json_encode(['date' => 'Tanggal', 'date_format' => 'Y-m-d H:i', 'description' => 'K', 'amount' => 'J'])], 'column_mapping'],
]);

it('requires a column mapping for the first generic import of an account', function () {
    upload(['column_mapping' => null])->assertUnprocessable()
        ->assertJsonValidationErrors(['column_mapping' => 'belum ada pemetaan tersimpan']);
});

it('reuses the last mapping of the account as a preset', function () {
    upload()->assertAccepted();

    upload(['column_mapping' => null], filename: 'mutasi-desember.csv')->assertAccepted()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.column_mapping.date', 'Tanggal')
        ->assertJsonPath('data.column_mapping.debit', 'Debet');

    // Akun lain tidak mewarisi preset akun ini.
    $other = Account::factory()->forUser(fx()->user)->create(['name' => 'Mandiri']);
    upload(['account_id' => $other->id, 'column_mapping' => null])->assertUnprocessable();
});

it('lists the user’s imports newest first and shows one', function () {
    $first = upload()->json('data.id');
    $second = upload(filename: 'lagi.csv')->json('data.id');
    upload(['account_id' => Account::factory()->create()->id]); // milik orang lain: 404, tidak tercatat

    actingAs(fx()->user)->getJson('/api/imports')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $second)
        ->assertJsonPath('data.1.id', $first);

    actingAs(fx()->user)->getJson("/api/imports/{$first}")->assertOk()
        ->assertJsonPath('data.original_filename', 'mutasi-november.csv');
});

it('parses an import only once even if the job runs again', function () {
    $id = upload()->json('data.id');
    $import = Import::query()->findOrFail($id);

    (new ParseImport(fx()->user->id, $id))->handle(app(StageImportRows::class));

    expect($import->rows()->count())->toBe(5)
        ->and($import->refresh()->status)->toBe(ImportStatus::Ready);
});

it('queues parsing after the upload instead of running it inline', function () {
    Queue::fake();

    upload()->assertAccepted()->assertJsonPath('data.status', 'pending');

    Queue::assertPushed(ParseImport::class, fn (ParseImport $job) => $job->userId === fx()->user->id);
});

it('marks the import failed when parsing crashes unexpectedly', function () {
    Queue::fake();
    $id = upload()->json('data.id');

    (new ParseImport(fx()->user->id, $id))->failed(new RuntimeException('boom'));

    $import = Import::query()->findOrFail($id);
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error)->toBe('Gagal memproses file. Coba unggah ulang.');
});

it('rate limits uploads per user', function () {
    Queue::fake();
    foreach (range(1, 10) as $_) {
        upload()->assertAccepted();
    }

    upload()->assertTooManyRequests();
});

it('enforces complete valid rows and explained invalid rows in the schema', function (array $row) {
    $import = Import::ownedBy(fx()->user)->create([
        'user_id' => fx()->user->id, 'account_id' => fx()->account->id, 'bank_format' => 'generic', 'original_filename' => 'x.csv',
    ]);

    expect(fn () => $import->rows()->create($row + ['row_number' => 1, 'raw' => []]))
        ->toThrow(QueryException::class, 'chk_import_row_valid');
})->with([
    'invalid without error' => [['status' => 'invalid']],
    'new without fingerprint' => [['status' => 'new', 'occurred_on' => '2026-11-03', 'amount' => -1]],
    'new without amount' => [['status' => 'new', 'occurred_on' => '2026-11-03', 'fingerprint' => str_repeat('a', 64)]],
    'new with zero amount' => [['status' => 'new', 'occurred_on' => '2026-11-03', 'amount' => 0, 'fingerprint' => str_repeat('a', 64)]],
    'duplicate without date' => [['status' => 'duplicate', 'amount' => -1, 'fingerprint' => str_repeat('a', 64)]],
]);

it('parses in a queue worker where nobody is logged in', function () {
    Queue::fake();
    $id = upload()->assertAccepted()->json('data.id');

    app('auth')->forgetGuards();
    expect(auth()->check())->toBeFalse();

    (new ParseImport(fx()->user->id, $id))->handle(app(StageImportRows::class));

    $import = Import::ownedBy(fx()->user)->findOrFail($id);
    expect($import->status)->toBe(ImportStatus::Ready)
        ->and($import->new_rows)->toBe(4)
        ->and($import->rows()->count())->toBe(5);
});
