<?php

use App\Models\Account;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
| Dedupe import (Keputusan #13) dari sudut pandang user, lewat API: upload → preview → commit.
| Kasus dasar lain ada di ImportUploadTest / CommitImportTest; tumpang tindih di OverlappingStatementTest.
*/

const DEDUPE_CSV = "Tanggal;Keterangan;Jumlah\n"
    ."03/11/2026;KOPI KENANGAN;-25.000\n"
    ."03/11/2026;KOPI KENANGAN;-25.000\n"
    ."03/11/2026;KOPI KENANGAN;-25.000\n"
    ."04/11/2026;GAJI;10.000.000\n";

beforeEach(function () {
    Storage::fake(Import::DISK);
    travelTo(new DateTimeImmutable('2026-11-25 10:00:00'));
});

/** @return TestResponse<Response> */
function uploadAs(User $user, Account $account, string $csv = DEDUPE_CSV): TestResponse
{
    return actingAs($user)->post('/api/imports', [
        'account_id' => $account->id,
        'format' => 'generic',
        'column_mapping' => json_encode(['date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Keterangan', 'amount' => 'Jumlah']),
        'file' => UploadedFile::fake()->createWithContent('mutasi.csv', $csv),
    ], ['Accept' => 'application/json']);
}

/** Upload + commit semua baris new; jumlah baris yang masuk. */
function importAndCommit(User $user, Account $account, string $csv = DEDUPE_CSV): int
{
    $id = uploadAs($user, $account, $csv)->assertAccepted()->json('data.id');
    $rows = Import::ownedBy($user)->findOrFail($id)->rows()->where('status', 'new')->pluck('id');

    return (int) actingAs($user)->postJson("/api/imports/{$id}/commit", [
        'rows' => $rows->map(fn (int $rowId) => ['id' => $rowId, 'category_id' => null])->all(),
    ])->assertOk()->json('meta.committed');
}

it('adds nothing when the same file is imported three times', function () {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->create();

    expect(importAndCommit($user, $account))->toBe(4)
        ->and(importAndCommit($user, $account))->toBe(0)
        ->and(importAndCommit($user, $account))->toBe(0)
        ->and(Transaction::ownedBy($user)->count())->toBe(4);
    assertLedgerBalanced();
});

it('keeps three identical coffees on the same day as three transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->create();

    importAndCommit($user, $account);

    expect(Transaction::ownedBy($user)->where('occurred_on', '2026-11-03')->where('amount', -25_000)->count())->toBe(3);
});

it('adds only the extra coffee when a later file has one more identical line', function () {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->create();
    importAndCommit($user, $account);

    $more = str_replace('04/11/2026;GAJI', "03/11/2026;KOPI KENANGAN;-25.000\n04/11/2026;GAJI", DEDUPE_CSV);

    expect(importAndCommit($user, $account, $more))->toBe(1)
        ->and(Transaction::ownedBy($user)->where('amount', -25_000)->count())->toBe(4);
});

it('dedupes per account: the same statement into two bank accounts is two sets of transactions', function () {
    $user = User::factory()->create();
    $bca = Account::factory()->forUser($user)->create(['name' => 'BCA']);
    $muamalat = Account::factory()->forUser($user)->create(['name' => 'Muamalat']);

    expect(importAndCommit($user, $bca))->toBe(4)
        ->and(importAndCommit($user, $muamalat))->toBe(4)
        ->and(importAndCommit($user, $bca))->toBe(0);
});

it('does not bring back an imported transaction the user deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->create();
    importAndCommit($user, $account);
    $salary = Transaction::ownedBy($user)->where('amount', 10_000_000)->sole();

    actingAs($user)->deleteJson("/api/transactions/{$salary->id}")->assertNoContent();

    $id = uploadAs($user, $account)->json('data.id');
    actingAs($user)->getJson("/api/imports/{$id}")->assertJsonPath('data.counts.duplicate', 4)->assertJsonPath('data.counts.new', 0);
    assertLedgerBalanced();
});

it('rejects statements for e-wallet and cash accounts', function (string $type) {
    $user = User::factory()->create();
    $account = Account::factory()->forUser($user)->create(['type' => $type]);

    uploadAs($user, $account)->assertUnprocessable()->assertJsonValidationErrors('account_id');
    expect(Import::ownedBy($user)->count())->toBe(0);
})->with(['ewallet', 'cash']);

it('hides an import from other users and lets nobody else commit it', function () {
    $owner = User::factory()->create();
    $account = Account::factory()->forUser($owner)->create();
    $id = uploadAs($owner, $account)->assertAccepted()->json('data.id');
    $rowIds = Import::ownedBy($owner)->findOrFail($id)->rows()->pluck('id');

    $intruder = User::factory()->create();
    actingAs($intruder)->getJson('/api/imports')->assertOk()->assertJsonCount(0, 'data');
    actingAs($intruder)->getJson("/api/imports/{$id}")->assertNotFound();
    actingAs($intruder)->getJson("/api/imports/{$id}/rows")->assertNotFound();
    actingAs($intruder)->postJson("/api/imports/{$id}/commit", [
        'rows' => $rowIds->map(fn (int $rowId) => ['id' => $rowId, 'category_id' => null])->all(),
    ])->assertNotFound();

    // Penyerang tidak bisa menyelipkan baris milik orang lain ke import miliknya sendiri.
    $ownAccount = Account::factory()->forUser($intruder)->create();
    $ownImport = uploadAs($intruder, $ownAccount, "Tanggal;Keterangan;Jumlah\n05/11/2026;PULSA;-50.000\n")->json('data.id');
    actingAs($intruder)->postJson("/api/imports/{$ownImport}/commit", [
        'rows' => [['id' => $rowIds->first(), 'category_id' => null]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['rows' => 'tidak ditemukan']);

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Import::ownedBy($owner)->findOrFail($id)->rows()->where('status', 'new')->count())->toBe(4);
});
