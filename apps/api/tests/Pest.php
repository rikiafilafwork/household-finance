<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Pest\TestSuite;
use Tests\Support\SpaClient;
use Tests\TestCase;

/*
| Feature test memakai TestCase Laravel + RefreshDatabase.
| Unit test murni (Money, RecurrenceCalculator, dll.) tidak butuh framework.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency: proses lain harus melihat data, jadi tidak boleh dibungkus transaksi.
// Tabel dikosongkan sebelum tiap test dan sesudah test terakhir supaya tidak bocor ke suite lain.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->afterEach(fn () => truncateAllTables())
    ->in('Concurrency');

/** Kosongkan semua tabel (kecuali migrations) tanpa menghapus schema. */
function truncateAllTables(): void
{
    $db = DB::connection();
    $tables = array_column($db->select(
        "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> 'migrations'"
    ), 'name');

    $db->statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $db->statement("TRUNCATE TABLE `{$table}`");
    }
    $db->statement('SET FOREIGN_KEY_CHECKS = 1');
}

/** Klien SPA (session cookie + CSRF), lihat tests/Support/SpaClient.php. */
function spa(): SpaClient
{
    // test() mengembalikan proxy; SpaClient butuh instance TestCase yang sedang berjalan.
    $test = TestSuite::getInstance()->test;

    if (! $test instanceof TestCase) {
        throw new LogicException('spa() hanya bisa dipakai di dalam Feature test.');
    }

    return new SpaClient($test);
}

/**
 * Invariant saldo (Keputusan #2/#3) untuk SEMUA wallet:
 * balance = opening_balance + SUM(amount) WHERE status = 'posted' AND deleted_at IS NULL.
 * (Command wallets:reconcile di #21 memakai rumus yang sama di kode aplikasi.)
 */
function assertLedgerBalanced(): void
{
    $mismatches = DB::select("
        SELECT w.id, w.balance, w.opening_balance + COALESCE(SUM(t.amount), 0) AS expected
          FROM wallets w
          LEFT JOIN transactions t
                 ON t.wallet_id = w.id AND t.status = 'posted' AND t.deleted_at IS NULL
         GROUP BY w.id, w.balance, w.opening_balance
        HAVING w.balance <> expected
    ");

    expect($mismatches)->toBe([], 'Saldo wallet meleset dari ledger: '.json_encode($mismatches));
}
