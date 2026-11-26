<?php

use App\Queries\DashboardQuery;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PlanFixture;

use function Pest\Laravel\artisan;

/*
| #62 — reports:benchmark mengubah index tabel transactions (ALTER TABLE = implicit commit), jadi
| test-nya di suite Concurrency (DatabaseTruncation), bukan Feature (RefreshDatabase).
*/

/** @return list<string> */
function transactionIndexes(): array
{
    $names = array_column(Schema::getIndexes('transactions'), 'name');
    sort($names);

    return $names;
}

it('measures every scenario for each index variant and always restores the schema', function () {
    $f = PlanFixture::architecture();
    $f->user->update(['email' => 'bench@example.test']);
    $before = transactionIndexes();
    $output = 'storage/app/benchmarks/test-reports.md';

    artisan("reports:benchmark --email=bench@example.test --month=2026-11 --runs=3 --variants=none,baseline,covering --output={$output}")
        ->assertSuccessful();

    expect(transactionIndexes())->toBe($before);

    $md = File::get(base_path($output));
    File::delete(base_path($output));
    expect($md)->toContain('| MySQL | 8.')
        ->toContain('## p95 per varian index (ms)')
        ->toContain('| Skenario | none | baseline | covering | Target |')
        ->toContain('Dashboard (GET /dashboard)')
        ->toContain('Export CSV 1 bulan')
        ->toContain('## EXPLAIN ANALYZE query paling lambat — varian `none`')
        ->toContain('-> ')                                      // pohon EXPLAIN ANALYZE
        ->and(substr_count($md, '### '))->toBe(3 * 8);
});

it('restores the schema even when a scenario crashes halfway', function () {
    $f = PlanFixture::architecture();
    $f->user->update(['email' => 'bench@example.test']);
    $before = transactionIndexes();
    // Dashboard (skenario ke-7) gagal setelah index varian `none` sudah dibuang.
    app()->bind(DashboardQuery::class, fn () => throw new RuntimeException('query gagal'));

    expect(fn () => artisan('reports:benchmark --email=bench@example.test --month=2026-11 --runs=1 --variants=none')->run())
        ->toThrow(RuntimeException::class, 'query gagal');

    expect(transactionIndexes())->toBe($before);
});

it('refuses to run without the measured user', function () {
    artisan('reports:benchmark --email=tidak-ada@example.test --variants=none')->assertFailed();
});
