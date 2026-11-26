<?php

use App\Actions\Accounts\LockAccounts;
use App\Actions\Imports\CommitImport;
use App\Enums\ImportStatus;
use App\Imports\FingerprintCalculator;
use App\Jobs\CheckEnvelopeThresholds;
use App\Models\Account;
use App\Models\Category;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

const COMMIT_STATEMENT = <<<'CSV'
    Tanggal;Keterangan;Debet;Kredit
    01/11/2026;GAJI NOVEMBER;;10.000.000,00
    03/11/2026;KOPI KENANGAN;25.000,00;
    03/11/2026;KOPI KENANGAN;25.000,00;
    05/11/2026;LISTRIK PLN;350.000,00;
    PEND;TARIK TUNAI;100.000,00;
    CSV;

final class CommitFixture
{
    public static self $current;

    public function __construct(
        public User $user,
        public Account $account,
        public Category $salary,
        public Category $food,
        public Category $bills,
    ) {}
}

function cfx(): CommitFixture
{
    return CommitFixture::$current;
}

beforeEach(function () {
    Storage::fake(Import::DISK);
    travelTo(new DateTimeImmutable('2026-11-25 10:00:00'));

    $user = User::factory()->create();
    CommitFixture::$current = new CommitFixture(
        $user,
        Account::factory()->forUser($user)->opening(1_000_000)->create(['name' => 'BCA']),
        Category::factory()->forUser($user)->income()->create(['name' => 'Gaji']),
        Category::factory()->forUser($user)->create(['name' => 'Makan']),
        Category::factory()->forUser($user)->create(['name' => 'Tagihan']),
    );
});

/** Upload + parse (queue sync); id import. */
function stagedImport(string $contents = COMMIT_STATEMENT, ?Account $account = null): int
{
    return (int) actingAs(cfx()->user)->post('/api/imports', [
        'account_id' => ($account ?? cfx()->account)->id,
        'format' => 'generic',
        'column_mapping' => json_encode([
            'date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Keterangan', 'debit' => 'Debet', 'credit' => 'Kredit',
        ]),
        'file' => UploadedFile::fake()->createWithContent('mutasi.csv', $contents),
    ], ['Accept' => 'application/json'])->assertAccepted()->assertJsonPath('data.status', 'ready')->json('data.id');
}

/** @return array<int, int> nomor baris file → import_row_id */
function rowIds(int $importId): array
{
    return Import::ownedBy(cfx()->user)->findOrFail($importId)->rows()->pluck('id', 'row_number')->all();
}

/**
 * @param  array<int, int|null>  $byRowNumber  nomor baris file → category_id
 * @return TestResponse<Response>
 */
function commitRows(int $importId, array $byRowNumber): TestResponse
{
    $ids = rowIds($importId);

    return actingAs(cfx()->user)->postJson("/api/imports/{$importId}/commit", [
        'rows' => array_map(fn (int $row, ?int $category) => ['id' => $ids[$row], 'category_id' => $category], array_keys($byRowNumber), $byRowNumber),
    ]);
}

/** @return list<array{string, int, ?int, ?string, string}> */
function ledger(): array
{
    return Transaction::ownedBy(cfx()->user)->orderBy('occurred_on')->orderBy('id')->get()
        ->map(fn (Transaction $t) => [$t->occurred_on->format('Y-m-d'), $t->amount, $t->category_id, $t->note, $t->type->value])
        ->all();
}

it('turns the selected rows into transactions and updates the balance once', function () {
    $id = stagedImport();
    $c = cfx();

    commitRows($id, [2 => $c->salary->id, 3 => $c->food->id, 4 => $c->food->id])->assertOk()
        ->assertJsonPath('data.status', 'committed')
        ->assertJsonPath('data.counts.committed', 3)
        ->assertJsonPath('data.counts.skipped', 1)
        ->assertJsonPath('meta', ['committed' => 3, 'duplicate' => 0, 'skipped' => 1]);

    expect(ledger())->toBe([
        ['2026-11-01', 10_000_000, $c->salary->id, 'GAJI NOVEMBER', 'income'],
        ['2026-11-03', -25_000, $c->food->id, 'KOPI KENANGAN', 'expense'],
        ['2026-11-03', -25_000, $c->food->id, 'KOPI KENANGAN', 'expense'],
    ])->and($c->account->refresh()->balance)->toBe(1_000_000 + 10_000_000 - 50_000);

    $transactions = Transaction::ownedBy($c->user)->get();
    expect($transactions->pluck('import_hash')->filter()->unique())->toHaveCount(3)
        ->and($transactions->pluck('plan_item_id')->filter())->toBeEmpty()
        ->and($transactions->pluck('client_id')->filter())->toBeEmpty();

    $rows = actingAs($c->user)->getJson("/api/imports/{$id}/rows")->json('data');
    expect(array_map(fn (array $r) => [$r['row_number'], $r['status'], $r['transaction_id'] !== null], $rows))->toBe([
        [2, 'committed', true], [3, 'committed', true], [4, 'committed', true], [5, 'skipped', false], [6, 'invalid', false],
    ]);

    assertLedgerBalanced();
    expect(Artisan::call('accounts:reconcile'))->toBe(0);
});

it('deletes the uploaded file after commit', function () {
    $id = stagedImport();
    $path = (string) Import::ownedBy(cfx()->user)->findOrFail($id)->file_path;
    Storage::disk(Import::DISK)->assertExists($path);

    commitRows($id, [2 => null])->assertOk();

    Storage::disk(Import::DISK)->assertMissing($path);
    expect(Import::ownedBy(cfx()->user)->findOrFail($id)->file_path)->toBeNull();
});

it('allows rows without a category', function () {
    $id = stagedImport();

    commitRows($id, [2 => null, 5 => null])->assertOk();

    expect(array_column(ledger(), 2))->toBe([null, null]);
});

it('adds nothing when the same statement is imported again after commit', function () {
    $first = stagedImport();
    commitRows($first, [2 => null, 3 => null, 4 => null, 5 => null])->assertOk();
    $balance = cfx()->account->refresh()->balance;

    $again = stagedImport();

    actingAs(cfx()->user)->getJson("/api/imports/{$again}")
        ->assertJsonPath('data.counts.new', 0)
        ->assertJsonPath('data.counts.duplicate', 4);
    expect(Transaction::ownedBy(cfx()->user)->count())->toBe(4)
        ->and(cfx()->account->refresh()->balance)->toBe($balance);
});

it('resumes a commit that failed halfway without doubling anything', function () {
    $id = stagedImport();

    // Chunk 2 baris; kunci akun gagal pada chunk kedua (mis. koneksi DB putus).
    app()->instance(CommitImport::class, new CommitImport(new class extends LockAccounts
    {
        private int $calls = 0;

        public function one(User $user, int $id): Account
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('koneksi DB putus');
            }

            return parent::one($user, $id);
        }
    }, chunkSize: 2));

    $selection = [2 => null, 3 => null, 4 => null, 5 => null];
    expect(fn () => commitRows($id, $selection)->assertServerError())->not->toThrow(Throwable::class);

    // Chunk pertama (gaji + kopi pertama) sudah masuk utuh, chunk kedua tidak sama sekali.
    expect(Transaction::ownedBy(cfx()->user)->count())->toBe(2)
        ->and(Import::ownedBy(cfx()->user)->findOrFail($id)->status)->toBe(ImportStatus::Committing);
    assertLedgerBalanced();

    app()->forgetInstance(CommitImport::class);
    commitRows($id, $selection)->assertOk()
        ->assertJsonPath('data.status', 'committed')
        ->assertJsonPath('meta.committed', 2)
        ->assertJsonPath('data.counts.committed', 4);

    expect(Transaction::ownedBy(cfx()->user)->count())->toBe(4)
        ->and(cfx()->account->refresh()->balance)->toBe(1_000_000 + 10_000_000 - 25_000 - 25_000 - 350_000);
    assertLedgerBalanced();
    expect(Artisan::call('accounts:reconcile'))->toBe(0);
});

it('marks rows that were imported elsewhere in the meantime as duplicates', function () {
    $id = stagedImport();
    $hash = FingerprintCalculator::fingerprint(cfx()->account->id, '2026-11-05', -350_000, 'LISTRIK PLN', 0);
    // Transaksi import lain masuk setelah parse (factory tidak menyentuh saldo, jadi saldo disesuaikan manual).
    Transaction::factory()->forAccount(cfx()->account)->expense(350_000)->create(['occurred_on' => '2026-11-05', 'import_hash' => $hash]);
    cfx()->account->decrement('balance', 350_000);

    commitRows($id, [2 => null, 5 => cfx()->bills->id])->assertOk()
        ->assertJsonPath('meta', ['committed' => 1, 'duplicate' => 1, 'skipped' => 2]);

    expect(Transaction::ownedBy(cfx()->user)->where('import_hash', $hash)->count())->toBe(1);
    assertLedgerBalanced();
});

it('lets the user commit a possible duplicate they confirmed', function () {
    Transaction::factory()->forAccount(cfx()->account)->expense(350_000)->create(['occurred_on' => '2026-11-05']);
    cfx()->account->decrement('balance', 350_000);
    $id = stagedImport();
    expect(actingAs(cfx()->user)->getJson("/api/imports/{$id}")->json('data.counts.possible_duplicate'))->toBe(1);

    commitRows($id, [5 => cfx()->bills->id])->assertOk()->assertJsonPath('meta.committed', 1);

    assertLedgerBalanced();
});

it('checks envelope thresholds once per category and month', function () {
    $id = stagedImport();
    Queue::fake();

    commitRows($id, [2 => cfx()->salary->id, 3 => cfx()->food->id, 4 => cfx()->food->id, 5 => null])->assertOk();

    Queue::assertPushed(CheckEnvelopeThresholds::class, 1);
    Queue::assertPushed(CheckEnvelopeThresholds::class, fn (CheckEnvelopeThresholds $job) => $job->categoryId === cfx()->food->id && $job->month === '2026-11-01');
});

it('rejects rows that cannot be committed and writes nothing', function (Closure $rows, string $message) {
    $id = stagedImport();
    $other = stagedImport("Tanggal;Keterangan;Debet;Kredit\n06/11/2026;PULSA;50.000,00;\n");

    actingAs(cfx()->user)->postJson("/api/imports/{$id}/commit", ['rows' => $rows(rowIds($id), rowIds($other), cfx())])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rows' => $message]);

    expect(Transaction::ownedBy(cfx()->user)->count())->toBe(0)
        ->and(cfx()->account->refresh()->balance)->toBe(1_000_000);
})->with([
    'invalid row' => [fn ($ids, $other, $c) => [['id' => $ids[6], 'category_id' => null]], 'duplikat/invalid'],
    'row of another import' => [fn ($ids, $other, $c) => [['id' => $other[2], 'category_id' => null]], 'tidak ditemukan di import ini'],
    'expense category on income' => [fn ($ids, $other, $c) => [['id' => $ids[2], 'category_id' => $c->food->id]], 'kategori pemasukan'],
    'income category on expense' => [fn ($ids, $other, $c) => [['id' => $ids[3], 'category_id' => $c->salary->id]], 'kategori pengeluaran'],
    'another user’s category' => [fn ($ids, $other, $c) => [['id' => $ids[3], 'category_id' => Category::factory()->create()->id]], 'tidak ditemukan'],
    'archived category' => [fn ($ids, $other, $c) => [['id' => $ids[3], 'category_id' => Category::factory()->forUser($c->user)->create(['archived_at' => now()])->id]], 'diarsip'],
]);

it('validates the request shape', function (array $payload, string $field) {
    $id = stagedImport();

    actingAs(cfx()->user)->postJson("/api/imports/{$id}/commit", $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing rows' => [[], 'rows'],
    'row without id' => [['rows' => [['category_id' => null]]], 'rows.0.id'],
    'row without category key' => [['rows' => [['id' => 1]]], 'rows.0.category_id'],
    'duplicate ids' => [['rows' => [['id' => 1, 'category_id' => null], ['id' => 1, 'category_id' => null]]], 'rows.0.id'],
]);

it('only commits a ready import once', function () {
    $id = stagedImport();
    commitRows($id, [2 => null])->assertOk();

    actingAs(cfx()->user)->postJson("/api/imports/{$id}/commit", ['rows' => []])
        ->assertUnprocessable()->assertJsonValidationErrors(['import' => 'sudah di-commit']);
    expect(Transaction::ownedBy(cfx()->user)->count())->toBe(1);
});

it('refuses imports that are not parsed', function (string $status, string $message) {
    Queue::fake();
    $id = (int) actingAs(cfx()->user)->post('/api/imports', [
        'account_id' => cfx()->account->id, 'format' => 'generic',
        'column_mapping' => json_encode(['date' => 0, 'date_format' => 'd/m/Y', 'description' => 1, 'amount' => 2, 'has_header' => false]),
        'file' => UploadedFile::fake()->createWithContent('m.csv', "03/11/2026;KOPI;-25.000\n"),
    ], ['Accept' => 'application/json'])->json('data.id');
    Import::ownedBy(cfx()->user)->whereKey($id)->update(['status' => $status]);

    actingAs(cfx()->user)->postJson("/api/imports/{$id}/commit", ['rows' => []])
        ->assertUnprocessable()->assertJsonValidationErrors(['import' => $message]);
})->with([
    'pending' => ['pending', 'belum selesai'],
    'parsing' => ['parsing', 'belum selesai'],
    'failed' => ['failed', 'unggah ulang'],
]);

it('refuses to commit into an archived account', function () {
    $id = stagedImport();
    cfx()->account->update(['archived_at' => now()]);

    commitRows($id, [2 => null])->assertUnprocessable()->assertJsonValidationErrors(['account_id' => 'diarsip']);
    expect(Transaction::ownedBy(cfx()->user)->count())->toBe(0);
});
