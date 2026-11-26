<?php

namespace App\Console\Commands;

use App\Enums\CategoryKind;
use App\Models\Transaction;
use App\Models\User;
use App\Plan\PlanMonth;
use App\Queries\CashFlowQuery;
use App\Queries\DashboardQuery;
use App\Reports\CashVsNonCashReport;
use App\Reports\CategoryBreakdownReport;
use App\Reports\MonthlySummaryReport;
use App\Reports\TransactionCsvExporter;
use App\Reports\TrendReport;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Bukti performa laporan & dashboard (Minggu 7, Keputusan #14): setiap skenario dijalankan N kali
 * (setelah 2 warm-up) untuk tiap varian index, dicatat p50/p95 dan jumlah query, plus
 * `EXPLAIN ANALYZE` query paling lambat. Hasil → Markdown (`make bench` menyalinnya ke
 * docs/benchmarks/reports.md).
 *
 * Varian index (schema SELALU dipulihkan ke migration di blok finally):
 *  - none:     idx_tx_user_date & idx_tx_user_cat_date dibuang. user_id masih ter-index lewat
 *              uq_tx_client (user_id, client_id) — FK wajib punya index — jadi MySQL menyaring per
 *              user tapi memindai SEMUA transaksi user itu, bukan rentang tanggal.
 *  - baseline: index dari migration.
 *  - covering: baseline + (user_id, occurred_on, type, deleted_at, plan_item_id, category_id, amount)
 *              untuk agregasi bulanan tanpa membaca baris tabel.
 *
 * Hanya untuk lingkungan lokal (mengubah index tabel transactions). Ditolak di production.
 */
class BenchmarkReports extends Command
{
    public const VARIANTS = ['none', 'baseline', 'covering'];

    /** Target hipotesis Architecture.md (p95, ms). */
    private const TARGET_REPORT_MS = 100;

    private const TARGET_DASHBOARD_MS = 150;

    private const COVERING = 'idx_bench_covering';

    protected $signature = 'reports:benchmark
        {--email=demo-large@example.test : User yang diukur (jalankan demo:seed-large dulu)}
        {--month= : Bulan acuan YYYY-MM (default: bulan lalu)}
        {--runs=50 : Jumlah run per skenario}
        {--variants=none,baseline,covering : Varian index, dipisah koma}
        {--output=storage/app/benchmarks/reports.md : File Markdown hasil}';

    protected $description = 'Ukur p50/p95 + EXPLAIN ANALYZE laporan & dashboard untuk beberapa varian index';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('reports:benchmark mengubah index tabel; tidak boleh di production.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', (string) $this->option('email'))->first();
        if ($user === null) {
            $this->error('User tidak ditemukan. Jalankan dulu: php artisan demo:seed-large');

            return self::FAILURE;
        }

        $variants = array_values(array_intersect(self::VARIANTS, explode(',', (string) $this->option('variants'))));
        $runs = max(1, (int) $this->option('runs'));
        $month = $this->option('month')
            ? PlanMonth::parse((string) $this->option('month'))
            : PlanMonth::parse(CarbonImmutable::now($user->timezone)->format('Y-m'))->previous();

        $results = [];
        try {
            foreach ($variants as $variant) {
                $this->applyVariant($variant);
                $this->info("Varian {$variant}");
                foreach ($this->scenarios($user, $month) as $name => $scenario) {
                    $results[$variant][$name] = $this->measure($scenario, $runs);
                    $r = $results[$variant][$name];
                    $this->line(sprintf('  %-34s p50 %7.1f ms   p95 %7.1f ms   %d query', $name, $r['p50'], $r['p95'], $r['queries']));
                }
            }
        } finally {
            $this->applyVariant('baseline');
        }

        $path = base_path((string) $this->option('output'));
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->markdown($user, $month, $runs, $results));
        $this->info("Hasil: {$path}");

        return self::SUCCESS;
    }

    /**
     * @return array<string, Closure(): mixed>
     */
    private function scenarios(User $user, PlanMonth $month): array
    {
        $yearStart = $month;
        for ($i = 1; $i < 12; $i++) {
            $yearStart = $yearStart->previous();
        }

        return [
            'Ringkasan bulan (summary)' => fn () => app(MonthlySummaryReport::class)->for($user, $month),
            'Kategori 12 bulan (by-category)' => fn () => app(CategoryBreakdownReport::class)->for($user, $yearStart, $month, CategoryKind::Expense),
            'Tren 6 bulan (trend)' => fn () => app(TrendReport::class)->for($user, $month, 6),
            'Cash flow 1 bulan' => fn () => app(CashFlowQuery::class)->for($user, $month->firstDay(), $month->lastDay()),
            'Cash flow 12 bulan' => fn () => app(CashFlowQuery::class)->for($user, $yearStart->firstDay(), $month->lastDay()),
            'Tunai vs non-tunai' => fn () => app(CashVsNonCashReport::class)->for($user, $month),
            'Dashboard (GET /dashboard)' => fn () => app(DashboardQuery::class)->for($user, $month),
            'Export CSV 1 bulan' => function () use ($user, $month) {
                $lines = 0;
                foreach (app(TransactionCsvExporter::class)->lines($user, $month->firstDay(), $month->lastDay()) as $_) {
                    $lines++;
                }

                return $lines;
            },
        ];
    }

    /**
     * @param  Closure(): mixed  $scenario
     * @return array{p50: float, p95: float, max: float, queries: int, slowest: array{sql: string, bindings: list<mixed>, ms: float}|null, explain: string}
     */
    private function measure(Closure $scenario, int $runs): array
    {
        $scenario();
        $scenario();                                              // warm-up: buffer pool & opcache

        $captured = [];
        DB::listen(function (QueryExecuted $q) use (&$captured) {
            $captured[] = ['sql' => $q->sql, 'bindings' => array_values($q->bindings), 'ms' => (float) $q->time];
        });

        $times = [];
        $queries = 0;
        for ($i = 0; $i < $runs; $i++) {
            $captured = [];
            $start = hrtime(true);
            $scenario();
            $times[] = (hrtime(true) - $start) / 1e6;
            $queries = count($captured);
        }
        DB::getEventDispatcher()?->forget(QueryExecuted::class);

        usort($captured, fn (array $a, array $b) => $b['ms'] <=> $a['ms']);
        $slowest = $captured[0] ?? null;
        sort($times);

        return [
            'p50' => self::percentile($times, 50),
            'p95' => self::percentile($times, 95),
            'max' => end($times) ?: 0.0,
            'queries' => $queries,
            'slowest' => $slowest,
            'explain' => $slowest === null ? '' : $this->explain($slowest['sql'], $slowest['bindings']),
        ];
    }

    /** @param  list<mixed>  $bindings */
    private function explain(string $sql, array $bindings): string
    {
        $rows = DB::select('EXPLAIN ANALYZE '.$sql, $bindings);
        $first = (array) ($rows[0] ?? []);

        return (string) (reset($first) ?: '');
    }

    /**
     * Nearest-rank percentile dari data terurut.
     *
     * @param  list<float>  $sorted
     */
    private static function percentile(array $sorted, int $p): float
    {
        $rank = (int) ceil($p / 100 * count($sorted));

        return $sorted[max(0, $rank - 1)] ?? 0.0;
    }

    private function applyVariant(string $variant): void
    {
        $has = fn (string $index) => collect(Schema::getIndexes('transactions'))->contains(fn (array $i) => $i['name'] === $index);
        $ensure = function (string $index, array $columns) use ($has) {
            if (! $has($index)) {
                Schema::table('transactions', fn ($t) => $t->index($columns, $index));
            }
        };
        $drop = function (string $index) use ($has) {
            if ($has($index)) {
                Schema::table('transactions', fn ($t) => $t->dropIndex($index));
            }
        };

        if (! in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException("Varian tidak dikenal: {$variant}");
        }
        if ($variant === 'none') {
            $drop(self::COVERING);
            $drop('idx_tx_user_date');
            $drop('idx_tx_user_cat_date');
        } else {
            $ensure('idx_tx_user_date', ['user_id', 'occurred_on']);
            $ensure('idx_tx_user_cat_date', ['user_id', 'category_id', 'occurred_on']);
            $variant === 'covering'
                ? $ensure(self::COVERING, ['user_id', 'occurred_on', 'type', 'deleted_at', 'plan_item_id', 'category_id', 'amount'])
                : $drop(self::COVERING);
        }
        DB::statement('ANALYZE TABLE transactions');
    }

    /**
     * @param  array<string, array<string, array{p50: float, p95: float, max: float, queries: int, slowest: array{sql: string, bindings: list<mixed>, ms: float}|null, explain: string}>>  $results
     */
    private function markdown(User $user, PlanMonth $month, int $runs, array $results): string
    {
        $mysql = (string) (DB::scalar('SELECT VERSION()') ?? '?');
        $total = DB::table('transactions')->count();         // seluruh tabel (noise), sengaja lintas user
        $own = Transaction::ownedBy($user)->count();
        $cpu = trim((string) @shell_exec("grep -m1 'model name' /proc/cpuinfo | cut -d: -f2")) ?: php_uname('m');
        $mem = (int) round(((int) trim((string) @shell_exec("awk '/MemTotal/ {print $2}' /proc/meminfo"))) / 1024 / 1024);
        $fmt = fn (float $ms) => number_format($ms, 1, ',', '.');
        $num = fn (int $n) => number_format($n, 0, ',', '.');
        $target = fn (string $name) => str_starts_with($name, 'Dashboard') ? self::TARGET_DASHBOARD_MS : self::TARGET_REPORT_MS;

        $md = "# Benchmark laporan & dashboard\n\n";
        $md .= '_Dihasilkan `php artisan reports:benchmark` pada '.CarbonImmutable::now($user->timezone)->format('Y-m-d H:i T').". Jangan diedit tangan; interpretasi ada di [README.md](README.md)._\n\n";
        $md .= "## Lingkungan\n\n| | |\n|---|---|\n";
        $md .= "| MySQL | {$mysql} |\n| PHP | ".PHP_VERSION." |\n| CPU (container) | {$cpu}, ".(int) trim((string) @shell_exec('nproc'))." core |\n| Memori container | ±{$mem} GB |\n";
        $md .= "| Data | {$num($own)} transaksi milik user yang diukur, {$num($total)} total di tabel (noise user lain) |\n";
        $md .= "| Bulan acuan | {$month->key()} |\n| Run | {$runs} per skenario setelah 2 warm-up; p50/p95 nearest-rank, dalam milidetik (waktu PHP end-to-end, termasuk query) |\n\n";

        $variants = array_keys($results);
        $md .= "## p95 per varian index (ms)\n\n| Skenario | ".implode(' | ', $variants)." | Target |\n|---|".str_repeat('---:|', count($variants))."---:|\n";
        foreach (array_keys(reset($results) ?: []) as $name) {
            $cells = array_map(fn (string $v) => $fmt($results[$v][$name]['p95']), $variants);
            $md .= "| {$name} | ".implode(' | ', $cells).' | < '.$target($name)." |\n";
        }

        foreach ($results as $variant => $scenarios) {
            $md .= "\n## Varian `{$variant}`\n\n| Skenario | p50 | p95 | maks | query/run | p95 vs target |\n|---|---:|---:|---:|---:|---|\n";
            foreach ($scenarios as $name => $r) {
                $ok = $r['p95'] < $target($name) ? 'memenuhi' : '**melebihi**';
                $md .= "| {$name} | {$fmt($r['p50'])} | {$fmt($r['p95'])} | {$fmt($r['max'])} | {$r['queries']} | {$ok} |\n";
            }
        }

        foreach ($results as $variant => $scenarios) {
            $md .= "\n## EXPLAIN ANALYZE query paling lambat — varian `{$variant}`\n";
            foreach ($scenarios as $name => $r) {
                if ($r['slowest'] === null) {
                    continue;
                }
                $md .= "\n### {$name}\n\n```sql\n".trim($r['slowest']['sql'])."\n```\n\n```text\n".trim($r['explain'])."\n```\n";
            }
        }

        return $md;
    }
}
