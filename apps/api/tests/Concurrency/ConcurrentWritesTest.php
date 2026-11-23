<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\PlanItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
| Bukti paralel sungguhan (Keputusan #15 & #3): N proses PHP terpisah, data ter-commit,
| semua dilepas di titik waktu yang sama. Karena client_id dicek SETELAH akun dikunci,
| retry yang bersamaan tersusun serial: tepat satu yang membuat, sisanya melihat hasilnya.
*/

/**
 * Jalankan $jobs bersamaan; kembalikan hasil JSON per proses.
 *
 * @param  list<array<string, mixed>>  $jobs
 * @return list<array{ok: bool, id?: string, created?: bool, status?: int|null, error?: string}>
 */
function runConcurrently(array $jobs): array
{
    $startAt = microtime(true) + 2.0; // beri waktu semua proses selesai boot
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ];

    $results = Process::pool(function (Pool $pool) use ($jobs, $startAt, $env) {
        foreach ($jobs as $job) {
            $pool->path(base_path())->env($env)->timeout(60)
                ->command(['php', 'tests/Concurrency/worker.php', json_encode($job + ['start_at' => $startAt])]);
        }
    })->start()->wait();

    $decoded = [];
    foreach ($results as $result) {
        /** @var array{ok: bool, id?: string, created?: bool, status?: int|null, error?: string} $row */
        $row = json_decode($result->output(), true) ?? ['ok' => false, 'error' => $result->errorOutput()];
        $decoded[] = $row;
    }

    return $decoded;
}

/**
 * @return array{0: User, 1: Account, 2: Account}
 */
function concurrencySetup(): array
{
    $owner = User::factory()->create();

    return [
        $owner,
        Account::factory()->forUser($owner)->opening(1_000_000)->create(['name' => 'A']),
        Account::factory()->forUser($owner)->opening(1_000_000)->create(['name' => 'B']),
    ];
}

it('creates exactly one transaction when 8 retries with the same client_id race', function () {
    [$owner, $account] = concurrencySetup();
    $job = [
        'op' => 'transaction', 'user_id' => $owner->id,
        'account_id' => $account->id, 'amount' => 25_000, 'client_id' => (string) Str::uuid(),
    ];

    $results = runConcurrently(array_fill(0, 8, $job));

    expect(array_column($results, 'ok'))->each->toBeTrue(json_encode($results))
        ->and(array_filter(array_column($results, 'created')))->toHaveCount(1)  // tepat satu yang membuat
        ->and(array_filter(array_column($results, 'created'), fn ($c) => ! $c))->toHaveCount(7) // tujuh melihat hasilnya
        ->and(array_unique(array_column($results, 'id')))->toHaveCount(1)
        ->and(Transaction::ownedBy($owner)->count())->toBe(1)
        ->and($account->fresh()?->balance)->toBe(975_000);
    assertLedgerBalanced();
});

it('creates exactly one transfer when 8 retries with the same client_id race', function () {
    [$owner, $a, $b] = concurrencySetup();
    $job = [
        'op' => 'transfer', 'user_id' => $owner->id,
        'from' => $a->id, 'to' => $b->id, 'amount' => 100_000, 'client_id' => (string) Str::uuid(),
    ];

    $results = runConcurrently(array_fill(0, 8, $job));

    expect(array_column($results, 'ok'))->each->toBeTrue(json_encode($results))
        ->and(array_filter(array_column($results, 'created')))->toHaveCount(1)
        ->and(array_unique(array_column($results, 'id')))->toHaveCount(1)
        ->and(Transaction::ownedBy($owner)->count())->toBe(2)
        ->and([$a->fresh()?->balance, $b->fresh()?->balance])->toBe([900_000, 1_100_000]);
    assertLedgerBalanced();
});

it('does not deadlock when transfers in opposite directions run at the same time', function () {
    [$owner, $a, $b] = concurrencySetup();

    $jobs = [];
    foreach (range(1, 10) as $i) {
        [$from, $to] = $i % 2 ? [$a, $b] : [$b, $a];
        $jobs[] = [
            'op' => 'transfer', 'user_id' => $owner->id,
            'from' => $from->id, 'to' => $to->id, 'amount' => $i * 1_000, 'client_id' => (string) Str::uuid(),
        ];
    }

    $results = runConcurrently($jobs);

    // A→B: 1+3+5+7+9 = 25 rb, B→A: 2+4+6+8+10 = 30 rb → A net +5 rb.
    expect(array_column($results, 'ok'))->each->toBeTrue(json_encode($results))
        ->and(Transaction::ownedBy($owner)->count())->toBe(20)
        ->and([$a->fresh()?->balance, $b->fresh()?->balance])->toBe([1_005_000, 995_000]);
    assertLedgerBalanced();
});

it('answers idempotently when retries with the same client_id target different accounts', function () {
    // Kunci akun A tidak menahan request yang mengunci akun B, jadi keduanya bisa lolos cek.
    // Unique index uq_tx_client menolak yang kalah → harus dijawab "sudah ada", bukan 500.
    [$owner, $a, $b] = concurrencySetup();
    $clientId = (string) Str::uuid();

    $jobs = [];
    foreach (range(1, 8) as $i) {
        $jobs[] = [
            'op' => 'transaction', 'user_id' => $owner->id,
            'account_id' => $i % 2 ? $a->id : $b->id, 'amount' => 10_000, 'client_id' => $clientId,
        ];
    }

    $results = runConcurrently($jobs);

    expect(array_column($results, 'ok'))->each->toBeTrue(json_encode($results))
        ->and(array_filter(array_column($results, 'created')))->toHaveCount(1)
        ->and(array_unique(array_column($results, 'id')))->toHaveCount(1)
        ->and(Transaction::ownedBy($owner)->count())->toBe(1);
    assertLedgerBalanced();
});

it('keeps the ledger consistent under mixed concurrent writes with some duplicate client_ids', function () {
    [$owner, $a, $b] = concurrencySetup();
    $dup = (string) Str::uuid();

    $jobs = [];
    foreach (range(1, 12) as $i) {
        $jobs[] = [
            'op' => 'transaction', 'user_id' => $owner->id,
            'account_id' => $i % 2 ? $a->id : $b->id, 'amount' => 10_000,
            'client_id' => $i <= 4 ? $dup : (string) Str::uuid(), // 4 retry duplikat (ke akun A/B bergantian)
        ];
    }

    $results = runConcurrently($jobs);

    expect(array_column($results, 'ok'))->each->toBeTrue(json_encode($results))
        ->and(Transaction::ownedBy($owner)->count())->toBe(9); // 1 dari 4 duplikat + 8 unik
    assertLedgerBalanced();
});

/**
 * @return array{0: User, 1: Account, 2: PlanItem}
 */
function checklistSetup(): array
{
    [$owner, $account] = concurrencySetup();
    $bills = Category::factory()->forUser($owner)->create();

    return [$owner, $account, PlanItem::factory()->forUser($owner)->create([
        'category_id' => $bills->id, 'planned_amount' => 500_000, 'account_id' => $account->id,
    ])];
}

it('checks a plan item exactly once when 8 devices with different client_ids race (others get 409)', function () {
    [$owner, $account, $item] = checklistSetup();

    $results = runConcurrently(array_map(fn () => [
        'op' => 'check', 'user_id' => $owner->id, 'plan_item_id' => $item->id, 'amount' => 0, 'client_id' => (string) Str::uuid(),
    ], range(1, 8)));

    expect(array_filter(array_column($results, 'ok')))->toHaveCount(1, json_encode($results))
        ->and(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'status'))->toBe(array_fill(0, 7, 409))
        ->and(Transaction::ownedBy($owner)->count())->toBe(1)
        ->and($account->fresh()?->balance)->toBe(500_000);
    assertLedgerBalanced();
});

it('answers 8 concurrent retries of the same check with one transaction and 200s', function () {
    [$owner, $account, $item] = checklistSetup();
    $job = ['op' => 'check', 'user_id' => $owner->id, 'plan_item_id' => $item->id, 'amount' => 0, 'client_id' => (string) Str::uuid()];

    $results = runConcurrently(array_fill(0, 8, $job));

    expect(array_column($results, 'ok'))->each->toBeTrue(json_encode($results))
        ->and(array_filter(array_column($results, 'created')))->toHaveCount(1)
        ->and(array_unique(array_column($results, 'id')))->toHaveCount(1)
        ->and(Transaction::ownedBy($owner)->count())->toBe(1)
        ->and($account->fresh()?->balance)->toBe(500_000);
});

it('stays consistent when checks and unchecks of one item interleave in parallel', function () {
    [$owner, $account, $item] = checklistSetup();

    $jobs = [];
    foreach (range(1, 10) as $i) {
        $jobs[] = ['op' => $i % 2 ? 'check' : 'uncheck', 'user_id' => $owner->id, 'plan_item_id' => $item->id, 'amount' => 0, 'client_id' => (string) Str::uuid()];
    }

    $results = runConcurrently($jobs);

    // Setiap kegagalan hanya boleh 409 (sudah dicentang), tidak ada deadlock/500.
    expect(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'status')))->toBeIn([[], [409]])
        ->and(Transaction::ownedBy($owner)->count())->toBeLessThanOrEqual(1)
        ->and($account->fresh()?->balance)->toBe(1_000_000 - 500_000 * Transaction::ownedBy($owner)->count());
    assertLedgerBalanced();
});
