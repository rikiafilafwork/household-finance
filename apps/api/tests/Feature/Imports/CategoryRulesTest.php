<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
| Stretch Minggu 6 (#57): kategori yang dipilih saat commit import jadi aturan, dan import
| berikutnya menyarankan kategori itu (import_rows.category_id saat status masih new).
*/

final class RulesFixture
{
    public static self $current;

    public function __construct(
        public User $user,
        public Account $account,
        public Category $food,
        public Category $shopping,
        public Category $salary,
    ) {}
}

function rfx(): RulesFixture
{
    return RulesFixture::$current;
}

beforeEach(function () {
    Storage::fake(Import::DISK);
    travelTo(new DateTimeImmutable('2026-11-25 10:00:00'));

    $user = User::factory()->create();
    RulesFixture::$current = new RulesFixture(
        $user,
        Account::factory()->forUser($user)->create(['name' => 'BCA']),
        Category::factory()->forUser($user)->create(['name' => 'Makan']),
        Category::factory()->forUser($user)->create(['name' => 'Belanja']),
        Category::factory()->forUser($user)->income()->create(['name' => 'Gaji']),
    );
});

/** @param  list<array{string, string, string}>  $lines  [tanggal, keterangan, jumlah] */
function stageLines(array $lines, ?User $user = null, ?Account $account = null): int
{
    $csv = "Tanggal;Keterangan;Jumlah\n".implode("\n", array_map(fn (array $l) => implode(';', $l), $lines))."\n";

    return (int) actingAs($user ?? rfx()->user)->post('/api/imports', [
        'account_id' => ($account ?? rfx()->account)->id,
        'format' => 'generic',
        'column_mapping' => json_encode(['date' => 'Tanggal', 'date_format' => 'd/m/Y', 'description' => 'Keterangan', 'amount' => 'Jumlah']),
        'file' => UploadedFile::fake()->createWithContent('mutasi.csv', $csv),
    ], ['Accept' => 'application/json'])->assertAccepted()->json('data.id');
}

/** @return array<string, int|null> deskripsi → category_id yang disarankan di preview */
function suggestions(int $importId, ?User $user = null): array
{
    $rows = actingAs($user ?? rfx()->user)->getJson("/api/imports/{$importId}/rows")->assertOk()->json('data');

    return array_column($rows, 'category_id', 'description');
}

/** @param  array<string, int|null>  $categories  deskripsi → category_id */
function commitWith(int $importId, array $categories): void
{
    $rows = Import::ownedBy(rfx()->user)->findOrFail($importId)->rows()->whereIn('status', ['new', 'possible_duplicate'])->get();

    actingAs(rfx()->user)->postJson("/api/imports/{$importId}/commit", [
        'rows' => $rows->map(fn ($r) => ['id' => $r->id, 'category_id' => $categories[$r->description] ?? null])->values()->all(),
    ])->assertOk();
}

it('learns from the categories chosen at commit and suggests them on the next import', function () {
    $c = rfx();
    $november = stageLines([
        ['01/11/2026', 'GAJI NOVEMBER PT CONTOH', '10.000.000'],
        ['03/11/2026', 'INDOMARET 1234', '-87.500'],
        ['04/11/2026', 'KOPI KENANGAN', '-25.000'],
        ['05/11/2026', 'LISTRIK PLN 512345678901', '-350.000'],
    ]);
    expect(array_filter(suggestions($november)))->toBe([]);   // belum ada aturan

    commitWith($november, [
        'GAJI NOVEMBER PT CONTOH' => $c->salary->id,
        'INDOMARET 1234' => $c->shopping->id,
        'KOPI KENANGAN' => $c->food->id,
        // listrik sengaja tanpa kategori → tidak jadi aturan
    ]);

    expect(CategoryRule::ownedBy($c->user)->orderBy('keyword')->pluck('category_id', 'keyword')->all())->toBe([
        'gaji november pt contoh' => $c->salary->id,
        'indomaret' => $c->shopping->id,
        'kopi kenangan' => $c->food->id,
    ]);

    $december = stageLines([
        ['01/12/2026', 'GAJI NOVEMBER PT CONTOH', '10.000.000'],
        ['02/12/2026', 'INDOMARET 5678', '-120.000'],
        ['03/12/2026', 'KOPI KENANGAN', '-25.000'],
        ['04/12/2026', 'LISTRIK PLN 512345678901', '-360.000'],
        ['05/12/2026', 'GOJEK', '-30.000'],
    ]);

    expect(suggestions($december))->toBe([
        'GAJI NOVEMBER PT CONTOH' => $c->salary->id,
        'INDOMARET 5678' => $c->shopping->id,
        'KOPI KENANGAN' => $c->food->id,
        'LISTRIK PLN 512345678901' => null,
        'GOJEK' => null,
    ]);
});

it('lets a correction replace the old rule', function () {
    $c = rfx();
    commitWith(stageLines([['03/11/2026', 'INDOMARET 1234', '-87.500']]), ['INDOMARET 1234' => $c->shopping->id]);

    $second = stageLines([['10/11/2026', 'INDOMARET 1234', '-30.000']]);
    expect(suggestions($second))->toBe(['INDOMARET 1234' => $c->shopping->id]);
    commitWith($second, ['INDOMARET 1234' => $c->food->id]);   // user mengoreksi saran

    expect(CategoryRule::ownedBy($c->user)->count())->toBe(1)
        ->and(suggestions(stageLines([['20/11/2026', 'INDOMARET 9', '-5.000']])))->toBe(['INDOMARET 9' => $c->food->id]);
});

it('does not suggest a category that was archived since', function () {
    $c = rfx();
    commitWith(stageLines([['03/11/2026', 'KOPI KENANGAN', '-25.000']]), ['KOPI KENANGAN' => $c->food->id]);
    $c->food->update(['archived_at' => now()]);

    expect(suggestions(stageLines([['04/11/2026', 'KOPI KENANGAN', '-25.000']])))->toBe(['KOPI KENANGAN' => null]);
});

it('drops rules when their category is deleted', function () {
    $c = rfx();
    $temporary = Category::factory()->forUser($c->user)->create(['name' => 'Sementara']);
    CategoryRule::ownedBy($c->user)->create(['user_id' => $c->user->id, 'keyword' => 'kopi', 'category_id' => $temporary->id]);

    actingAs($c->user)->deleteJson("/api/categories/{$temporary->id}")->assertNoContent();

    expect(CategoryRule::ownedBy($c->user)->count())->toBe(0);
});

it('does not suggest for rows that cannot be imported', function () {
    $c = rfx();
    commitWith(stageLines([['03/11/2026', 'KOPI KENANGAN', '-25.000']]), ['KOPI KENANGAN' => $c->food->id]);

    // File yang sama: barisnya duplicate → tanpa saran.
    expect(suggestions(stageLines([['03/11/2026', 'KOPI KENANGAN', '-25.000']])))->toBe(['KOPI KENANGAN' => null]);
});

it('keeps rules per user', function () {
    $c = rfx();
    commitWith(stageLines([['03/11/2026', 'KOPI KENANGAN', '-25.000']]), ['KOPI KENANGAN' => $c->food->id]);

    $other = User::factory()->create();
    $otherAccount = Account::factory()->forUser($other)->create();
    Category::factory()->forUser($other)->create(['name' => 'Makan']);
    $id = stageLines([['03/11/2026', 'KOPI KENANGAN', '-25.000']], $other, $otherAccount);

    expect(suggestions($id, $other))->toBe(['KOPI KENANGAN' => null]);
});
